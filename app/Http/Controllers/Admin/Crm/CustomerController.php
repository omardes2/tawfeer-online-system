<?php

namespace App\Http\Controllers\Admin\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\BlockCustomerRequest;
use App\Http\Requests\Crm\MergeCustomerRequest;
use App\Http\Requests\Crm\StoreCustomerRequest;
use App\Http\Requests\Crm\StoreNoteRequest;
use App\Http\Requests\Crm\UpdateCustomerRequest;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $query = Customer::query()->withCount('orders')->withOutstandingBalance();
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $normalized = $this->service->normalizePhone((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('primary_phone', 'like', "%{$normalized}%"));
        }

        return view('admin.crm.customers.index', [
            'customers' => $query->latest('id')->paginate(20)->withQueryString(),
            'search' => $request->input('search'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('admin.crm.customers.form', ['customer' => new Customer]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $data = $request->safe()->except(['phones', 'addresses', 'contacts']);
        if ($request->user()->branch_id) {
            $data['branch_id'] = $request->user()->branch_id;
        }

        /*
            تحذيرٌ لا منع.

            المكرّر يُنشأ لأن المُدخِل لا يرى أن الاسم موجود، فيتفرّق دَينُ رجلٍ
            واحد على سجلّين. والمنعُ خطأ مقابل: «زبون» اسمٌ لعشرة مختلفين، ومنعُه
            يُعطّل الإدخال. فيُعرض المتشابه ويُترك القرار — ومن أصرّ أعاد الإرسال
            بـ`confirm_duplicate`.
        */
        if (! $request->boolean('confirm_duplicate')) {
            $matches = $this->service->lookAlikes($data['name'] ?? null, $data['primary_phone'] ?? null);

            if ($matches->isNotEmpty()) {
                return back()->withInput()->with('duplicate_matches', $matches);
            }
        }

        $customer = $this->service->create($data, $request->validated('phones', []), $request->validated('addresses', []), $request->validated('contacts', []));

        return redirect()->route('admin.crm.customers.show', $customer)->with('success', __('أُضيف العميل.'));
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('admin.crm.customers.form', ['customer' => $customer->load(['phones', 'addresses', 'contacts'])]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->service->update(
            $customer,
            $request->safe()->except(['phones', 'addresses', 'contacts']),
            $request->has('phones') ? $request->validated('phones', []) : null,
            $request->has('addresses') ? $request->validated('addresses', []) : null,
            $request->has('contacts') ? $request->validated('contacts', []) : null,
        );

        return redirect()->route('admin.crm.customers.show', $customer)->with('success', __('حُدّث العميل.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $this->service->delete($customer);

        return redirect()->route('admin.crm.customers.index')->with('success', __('حُذف العميل.'));
    }

    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        $customer->load(['phones', 'addresses', 'contacts', 'customerNotes.author', 'glAccount']);

        $orders = $customer->orders()->latest('id')->paginate(15);

        $receipts = FinancialVoucher::where('customer_id', $customer->id)
            ->where('kind', 'receipt')
            ->with('treasury:id,name')
            ->latest('voucher_date')->latest('id')->paginate(15, ['*'], 'receipts_page');

        $statement = $this->buildStatement($customer);

        /*
            الإجماليات صافيةً بعد عكس القيود.

            كانت كل حركة دائنة تُحسب «مقبوضات»، وقيدُ عكسِ فاتورةٍ محذوفة دائنٌ
            على ذمّة العميل — فتظهر ٧٠ مقبوضات لعميلٍ لم يدفع شيئًا، وفاتورتُه
            أصلًا محذوفة. والمبيعات كانت تُحسب بالمثل فتبقى قائمةً بعد حذفها.

            القيد العاكس يحمل `reverses_entry_id`، فيُطرح من الجانب الذي عكسه:
            عكسُ مبيعةٍ يُنقص المبيعات، وعكسُ سندِ قبضٍ يُنقص المقبوضات.
        */
        $normal = $statement->where('is_reversal', false);
        $reversals = $statement->where('is_reversal', true);

        $sales = round($normal->sum('debit') - $reversals->sum('credit'), 2);
        $received = round($normal->sum('credit') - $reversals->sum('debit'), 2);

        // الرصيد من الحركات كلّها كما هي — آخر رصيد في الكشف يطابق البطاقة.
        $balance = round($statement->sum('debit') - $statement->sum('credit'), 2);

        // مرشَّحو الدمج لمن يملك الصلاحية وحده: الاستعلام لا يُدفع ثمنُه لمن
        // لا يرى الزرّ أصلًا.
        $mergeCandidates = auth()->user()?->can('crm.customers.merge')
            ? $this->service->mergeCandidatesFor($customer)
            : collect();

        return view('admin.crm.customers.show', compact(
            'customer', 'orders', 'receipts', 'statement', 'sales', 'received', 'balance', 'mergeCandidates',
        ));
    }

    /**
     * كشف حساب العميل: حركات حسابه الفرعي في «ذمم العملاء» (القيود المُرحّلة) برصيد متحرّك.
     * المبيعات على الحساب مدينة (تزيد المستحق)، والمقبوضات دائنة (تُنقصه). الحساب أصل مدين الطبيعة.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildStatement(Customer $customer): Collection
    {
        $account = $customer->glAccount;
        if (! $account) {
            return collect();
        }

        $running = 0.0;

        return $account->lines()
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->with('entry:id,number,entry_date,description,reverses_entry_id')
            ->get()
            ->sortBy(fn ($l) => [optional($l->entry)->entry_date?->format('Y-m-d'), $l->id])
            ->values()
            ->map(function ($line) use (&$running) {
                $debit = (float) $line->debit;
                $credit = (float) $line->credit;
                $running += $debit - $credit;

                return [
                    'date' => $line->entry?->entry_date,
                    'ref' => $line->entry?->number,
                    'desc' => $line->entry?->description,
                    'debit' => $debit,
                    'credit' => $credit,
                    // قيدٌ عاكس لقيدٍ آخر — يُطرح من إجماليه لا يُضاف إلى مقابله.
                    'is_reversal' => $line->entry?->reverses_entry_id !== null,
                    'balance' => round($running, 2),
                ];
            });
    }

    public function addNote(StoreNoteRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->service->addNote($customer, $request->validated('body'));

        return back()->with('success', __('أُضيفت الملاحظة.'));
    }

    public function block(BlockCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('block', $customer);

        $this->service->block($customer, $request->validated('reason'));

        return back()->with('success', __('حُظر العميل.'));
    }

    public function unblock(Customer $customer): RedirectResponse
    {
        $this->authorize('block', $customer);

        $this->service->unblock($customer);

        return back()->with('success', __('رُفع الحظر.'));
    }

    /** شاشة المكرّرين: تعرض المجموعات ولا تدمج — القرار للمستخدم (BR-CUST-14). */
    public function duplicates(): View
    {
        $this->authorize('merge', new Customer);

        return view('admin.crm.customers.duplicates', [
            'groups' => $this->service->duplicateGroups(),
        ]);
    }

    public function merge(MergeCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('merge', $customer);

        $target = Customer::where('uuid', $request->validated('target'))->firstOrFail();
        $this->authorize('merge', $target);

        try {
            $this->service->merge($customer, $target);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.crm.customers.show', $target)
            ->with('success', __('دُمج «:from» في «:to» — انتقلت طلباته وسنداته ورصيده.', [
                'from' => $customer->name,
                'to' => $target->name,
            ]));
    }
}
