<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreTreasuryRequest;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * أساس متحكّم الخزائن/البنوك (Phase 7.1) — متحكّم رفيع فوق TreasuryService.
 * الصندوق والبنك يتشاركان المنطق ويختلفان في النوع والصلاحية والقالب. RTL + عربي/إنجليزي.
 */
abstract class TreasuryControllerBase extends Controller
{
    public function __construct(protected readonly TreasuryService $service) {}

    /** cash | bank */
    abstract protected function type(): string;

    /** بادئة الصلاحية: cashboxes | banks */
    abstract protected function perm(): string;

    /** مسار القوالب: cashboxes | banks */
    abstract protected function views(): string;

    /** اسم المسار: cashboxes | banks */
    abstract protected function routeName(): string;

    public function index(Request $request): View
    {
        $this->authorize2('view');

        $treasuries = Treasury::query()->type($this->type())
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')->orWhere('code', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('is_default')->orderBy('name')->get()
            ->each(fn (Treasury $t) => $t->current_balance = $this->service->balance($t));

        return view('admin.accounting.treasury.index', array_merge($this->ctx(), [
            'treasuries' => $treasuries,
            'search' => $request->input('search'),
            'total' => $treasuries->sum('current_balance'),
        ]));
    }

    public function create(): View
    {
        $this->authorize2('manage');

        return view('admin.accounting.treasury.form', array_merge($this->ctx(), $this->formData(new Treasury(['type' => $this->type()]))));
    }

    public function store(StoreTreasuryRequest $request): RedirectResponse
    {
        $this->authorize2('manage');
        $this->service->create(array_merge($request->validated(), ['type' => $this->type()]));

        return redirect()->route("admin.accounting.{$this->routeName()}.index")->with('success', __('تمت الإضافة.'));
    }

    public function edit(Treasury $treasury): View
    {
        $this->authorize2('manage');
        abort_unless($treasury->type === $this->type(), 404);

        return view('admin.accounting.treasury.form', array_merge($this->ctx(), $this->formData($treasury)));
    }

    public function update(StoreTreasuryRequest $request, Treasury $treasury): RedirectResponse
    {
        $this->authorize2('manage');
        abort_unless($treasury->type === $this->type(), 404);
        $this->service->update($treasury, $request->validated());

        return redirect()->route("admin.accounting.{$this->routeName()}.index")->with('success', __('تم التحديث.'));
    }

    public function show(Treasury $treasury): View
    {
        $this->authorize2('view');
        abort_unless($treasury->type === $this->type(), 404);

        $movements = $this->service->movements($treasury, 100);

        return view('admin.accounting.treasury.show', array_merge($this->ctx(), [
            'treasury' => $treasury->load('glAccount'),
            'balance' => $this->service->balance($treasury),
            'movements' => $movements,
            'parties' => $this->resolveParties($movements),
        ]));
    }

    /**
     * اسم الشخص/الجهة المستفيدة من كل حركة (المستفيد) — يُشتقّ من السند المالي المرتبط
     * بالقيد (سند قبض/صرف أو دفعة فاتورة). المفتاح = معرّف القيد. غير الموجود لا اسم له.
     *
     * @param  Collection<int, JournalLine>  $movements
     * @return array<int, string>
     */
    private function resolveParties(Collection $movements): array
    {
        $entryIds = $movements->map(fn ($m) => $m->entry?->id)->filter()->unique()->all();
        if (empty($entryIds)) {
            return [];
        }

        return FinancialVoucher::whereIn('journal_entry_id', $entryIds)
            ->with(['supplier:id,name', 'customer:id,name', 'employee:id,name'])
            ->get(['id', 'journal_entry_id', 'supplier_id', 'customer_id', 'employee_id', 'party_name'])
            ->mapWithKeys(fn (FinancialVoucher $v) => [
                $v->journal_entry_id => $v->supplier?->name ?? $v->customer?->name ?? $v->employee?->name ?? $v->party_name,
            ])
            ->filter()
            ->all();
    }

    /** سياق العرض المشترك: بادئة المسار ونوع الخزينة. */
    private function ctx(): array
    {
        return ['rp' => $this->routeName(), 'isBank' => $this->type() === 'bank'];
    }

    public function destroy(Treasury $treasury): RedirectResponse
    {
        $this->authorize2('manage');
        abort_unless($treasury->type === $this->type(), 404);
        $this->service->delete($treasury);

        return redirect()->route("admin.accounting.{$this->routeName()}.index")->with('success', __('تم الحذف.'));
    }

    private function formData(Treasury $treasury): array
    {
        // حسابات GL أصول قابلة للترحيل غير مرتبطة بخزينة (والمرتبط الحالي إن وُجد).
        $linked = Treasury::whereNotNull('gl_account_id')
            ->where('id', '!=', $treasury->id)->pluck('gl_account_id');

        return [
            'treasury' => $treasury,
            'accounts' => Account::query()->postable()->where('type', 'asset')
                ->whereNotIn('id', $linked)->orderBy('code')->get(),
            'suggestedCode' => $treasury->exists ? $treasury->code : $this->service->nextCode($this->type()),
        ];
    }

    protected function authorize2(string $action): void
    {
        abort_unless(auth()->user()?->can("accounting.{$this->perm()}.{$action}") ?? false, 403);
    }
}
