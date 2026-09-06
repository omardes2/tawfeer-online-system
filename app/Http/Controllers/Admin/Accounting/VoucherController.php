<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreVoucherRequest;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ExpenseCategory;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Crm\Models\Customer;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Order;
use App\Support\XlsxExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * سندات القبض/الصرف/المصروفات/الإيرادات (Phase 7.1) — متحكّم موحّد حسب النوع (kind).
 * كل ترحيل/عكس يمرّ عبر VoucherService (قيد متوازن). الصلاحية تُفحص لكل نوع.
 */
class VoucherController extends Controller
{
    /** kind → مورد الصلاحية. */
    private const RESOURCE = ['receipt' => 'receipts', 'payment' => 'payments', 'expense' => 'expenses', 'income' => 'income'];

    /** kind → أنواع حسابات الطرف المقابل المقترحة. */
    private const COUNTER_TYPES = [
        'receipt' => ['revenue', 'asset', 'liability'],
        'payment' => ['expense', 'liability', 'asset'],
        'expense' => ['expense'],
        'income' => ['revenue'],
    ];

    public function __construct(private readonly VoucherService $service) {}

    public function index(Request $request, string $kind): View
    {
        $this->auth($kind, 'view');

        $vouchers = $this->filtered($request, $kind)->latest('id')->paginate(20)->withQueryString();

        return view('admin.accounting.vouchers.index', [
            'kind' => $kind,
            'vouchers' => $vouchers,
            'filters' => $request->only(['status', 'search', 'from', 'to']),
            // رقم التتبّع تُطابَق به فاتورة شركة التوصيل سطرًا سطرًا — سندُ
            // تحصيل COD يحمل رقم الطلب مرجعًا، والشركة تكتب رقم التتبّع.
            'trackings' => $this->trackings($vouchers->getCollection()),
        ]);
    }

    /**
     * استعلام السندات بعد الفلاتر — تقرؤه الشاشة والتصدير معًا.
     *
     * كان التصدير يقرأ التاريخين وحدهما ويتجاهل الحالة والبحث، فيُصدَّر ملفٌّ
     * أوسع مما على الشاشة: يُفلتر المستخدم «المُرحّلة» ثم يجد الملغاة في ملفّه.
     */
    private function filtered(Request $request, string $kind): Builder
    {
        return FinancialVoucher::query()->kind($kind)
            ->with(['treasury', 'counterAccount'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', '%'.$request->string('search').'%')
                ->orWhere('party_name', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('voucher_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('voucher_date', '<=', $request->date('to')));
    }

    /**
     * رقم تتبّع كل سند — من مرجعه إلى الطلب.
     *
     * استعلامٌ واحد لكل الصفحة لا استعلامٌ لكل سند: الصفحة عشرون سندًا
     * والتصدير مئات.
     *
     * ويُقرأ الطلب عرضًا فقط — Protected Delivery Integration — Do Not Modify:
     * لا يُكتب رقم التتبّع ولا يُطلب من شركة التوصيل.
     *
     * @param  Collection<int, FinancialVoucher>  $vouchers
     * @return array<int, string>
     */
    private function trackings(Collection $vouchers): array
    {
        $references = $vouchers->pluck('reference')->filter()->unique()->values()->all();

        if ($references === []) {
            return [];
        }

        $orders = Order::whereIn('number', $references)->pluck('tracking_number', 'number');

        return $vouchers->mapWithKeys(fn (FinancialVoucher $v) => [
            $v->id => $orders->get((string) $v->reference),
        ])->filter()->all();
    }

    public function create(string $kind): View
    {
        $this->auth($kind, 'create');

        return view('admin.accounting.vouchers.form', $this->formData($kind, new FinancialVoucher(['kind' => $kind, 'voucher_date' => now()->toDateString()])));
    }

    public function store(StoreVoucherRequest $request, string $kind): RedirectResponse
    {
        $this->auth($kind, 'create');
        $data = $this->resolveExpenseAccount($kind, $request->validated());
        $data['attachments'] = $this->storeAttachments($request);

        $voucher = $this->service->create($kind, $data);

        return redirect()->route('admin.accounting.vouchers.show', [$kind, $voucher])->with('success', __('حُفظ السند كمسودّة.'));
    }

    public function show(string $kind, FinancialVoucher $voucher): View
    {
        $this->auth($kind, 'view');
        abort_unless($voucher->kind === $kind, 404);

        return view('admin.accounting.vouchers.show', [
            'kind' => $kind,
            'voucher' => $voucher->load(['treasury.glAccount', 'counterAccount', 'expenseCategory', 'customer', 'supplier', 'employee', 'journalEntry', 'creator']),
        ]);
    }

    public function edit(string $kind, FinancialVoucher $voucher): View
    {
        $this->auth($kind, 'create');
        abort_unless($voucher->kind === $kind, 404);
        abort_if(in_array($voucher->status, ['reversed', 'cancelled', 'rejected'], true), 403, __('لا يمكن تعديل سند بهذه الحالة.'));

        return view('admin.accounting.vouchers.form', $this->formData($kind, $voucher));
    }

    public function update(StoreVoucherRequest $request, string $kind, FinancialVoucher $voucher): RedirectResponse
    {
        $this->auth($kind, 'create');
        abort_unless($voucher->kind === $kind, 404);
        abort_if(in_array($voucher->status, ['reversed', 'cancelled', 'rejected'], true), 403, __('لا يمكن تعديل سند بهذه الحالة.'));

        $data = $this->resolveExpenseAccount($kind, $request->validated());
        $newAttachments = $this->storeAttachments($request);
        if ($newAttachments) {
            $data['attachments'] = array_merge($voucher->attachments ?? [], $newAttachments);
        }

        // المُرحّل: عكس + قيد مُصحّح (يتطلّب صلاحية الترحيل). غير المُرحّل: تعديل مباشر.
        if ($voucher->status === 'posted') {
            $this->auth($kind, 'post');
            $this->service->repost($voucher, $data);
        } else {
            $this->service->update($voucher, $data);
        }

        return redirect()->route('admin.accounting.vouchers.show', [$kind, $voucher])
            ->with('success', __('حُدّث السند وانعكس محاسبيًا.'));
    }

    public function approve(string $kind, FinancialVoucher $voucher): RedirectResponse
    {
        $this->auth($kind, 'approve');
        abort_unless($voucher->kind === $kind, 404);
        $this->service->approve($voucher);

        return back()->with('success', __('اعتُمد السند.'));
    }

    public function reject(string $kind, FinancialVoucher $voucher): RedirectResponse
    {
        $this->auth($kind, 'approve');
        abort_unless($voucher->kind === $kind, 404);
        $this->service->reject($voucher);

        return back()->with('success', __('رُفض السند.'));
    }

    public function cancel(string $kind, FinancialVoucher $voucher): RedirectResponse
    {
        $this->auth($kind, 'create');
        abort_unless($voucher->kind === $kind, 404);
        $this->service->cancel($voucher);

        return back()->with('success', __('أُلغي السند.'));
    }

    public function post(string $kind, FinancialVoucher $voucher): RedirectResponse
    {
        $this->auth($kind, 'post');
        abort_unless($voucher->kind === $kind, 404);
        $this->service->post($voucher);

        return back()->with('success', __('رُحّل السند.'));
    }

    public function reverse(string $kind, FinancialVoucher $voucher, Request $request): RedirectResponse
    {
        $this->auth($kind, 'post');
        abort_unless($voucher->kind === $kind, 404);
        $this->service->reverse($voucher, $request->string('reason')->toString() ?: null);

        return back()->with('success', __('عُكس السند بقيد عاكس.'));
    }

    public function print(string $kind, FinancialVoucher $voucher): View
    {
        $this->auth($kind, 'view');
        abort_unless($voucher->kind === $kind, 404);

        return view('admin.accounting.vouchers.print', [
            'kind' => $kind,
            'voucher' => $voucher->load(['treasury', 'counterAccount', 'customer', 'supplier', 'employee']),
        ]);
    }

    /**
     * تصدير السندات ملفَّ Excel — **بنفس فلاتر الشاشة** وبرقم التتبّع.
     *
     * وxlsx لا CSV: أرقام التتبّع طويلة، وExcel يقرأ CSV فيحوّل الطويل منها إلى
     * صيغةٍ أسّية (`7.4999E+06`) فلا يُطابَق بها شيء. وفي xlsx تُكتب نصًّا.
     */
    public function export(Request $request, string $kind): BinaryFileResponse
    {
        $this->auth($kind, 'view');

        $rows = $this->filtered($request, $kind)->orderBy('voucher_date')->orderBy('id')->get();
        $trackings = $this->trackings($rows);

        $head = [
            __('الرقم'), __('التاريخ'), __('رقم التتبّع'), __('الحالة'),
            __('الخزينة'), __('الحساب المقابل'), __('الطرف'), __('المبلغ'),
        ];

        return XlsxExporter::download(
            $kind.'-vouchers-'.now()->format('Ymd'),
            $head,
            fn () => yield from $rows->map(fn (FinancialVoucher $v) => [
                $v->number,
                $v->voucher_date->toDateString(),
                (string) ($trackings[$v->id] ?? ''),
                __('accounting.status.'.$v->status),
                $v->treasury?->name ?? '',
                $v->counterAccount?->name ?? '',
                $v->party_name ?? '',
                round((float) $v->amount, 2),
            ]),
            [
                // ترويسةٌ تقول أي فلترٍ أنتج الملفّ: ملفٌّ بلا مدّته ولا حالته
                // يُقرأ كشفًا كاملًا وهو مُصفّى.
                [__('سندات'), __('accounting.kind.'.$kind)],
                [__('من'), $request->input('from') ?: '—', __('إلى'), $request->input('to') ?: '—'],
                [__('الحالة'), $request->filled('status') ? __('accounting.status.'.$request->string('status')) : __('الكل')],
            ],
        );
    }

    // ————————————————————————————————— داخلي —————————————————————————————————

    /**
     * سند المصروف يختار تصنيفًا؛ الحساب يُشتقّ منه.
     *
     * الحساب هو ما يُرحَّل عليه القيد كما كان — التصنيف طبقةٌ فوقه لا بديلٌ عنه.
     * وغيابُ التصنيف عند التعديل يترك الحساب على حاله (سندٌ قديم بلا تصنيف).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveExpenseAccount(string $kind, array $data): array
    {
        if ($kind !== 'expense' || empty($data['expense_category_id'])) {
            unset($data['expense_category_id']);

            return $data;
        }

        $category = ExpenseCategory::findOrFail($data['expense_category_id']);
        $data['counter_account_id'] = $category->account_id;

        return $data;
    }

    private function formData(string $kind, FinancialVoucher $voucher): array
    {
        return [
            'kind' => $kind,
            'voucher' => $voucher,
            'treasuries' => Treasury::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'accounts' => Account::query()->postable()->whereIn('type', self::COUNTER_TYPES[$kind])->orderBy('code')->get(),
            // التصنيفات النشطة + تصنيف السند الحالي وإن عُطّل، فلا يُفقد عند التعديل.
            'categories' => $kind === 'expense'
                ? ExpenseCategory::with('account')
                    ->where(fn ($q) => $q->active()->orWhere('id', $voucher->expense_category_id))
                    ->ordered()->get()
                : collect(),
            'customers' => in_array($kind, ['receipt', 'income'], true) ? Customer::orderBy('name')->limit(500)->get(['id', 'name']) : collect(),
            'suppliers' => in_array($kind, ['payment', 'expense'], true) ? Supplier::orderBy('name')->limit(500)->get(['id', 'name']) : collect(),
        ];
    }

    /** @return array<int, array{path:string, name:string}> */
    private function storeAttachments(Request $request): array
    {
        $out = [];
        foreach ((array) $request->file('attachments', []) as $file) {
            if ($file) {
                $out[] = ['path' => $file->store('vouchers', 'public'), 'name' => $file->getClientOriginalName()];
            }
        }

        return $out;
    }

    private function auth(string $kind, string $action): void
    {
        abort_unless(isset(self::RESOURCE[$kind]), 404);
        abort_unless(auth()->user()?->can('accounting.'.self::RESOURCE[$kind].'.'.$action) ?? false, 403);
    }
}
