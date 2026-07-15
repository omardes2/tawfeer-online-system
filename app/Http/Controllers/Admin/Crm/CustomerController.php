<?php

namespace App\Http\Controllers\Admin\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\BlockCustomerRequest;
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

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $query = Customer::query()->withCount('orders');
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
        // الرصيد/الإجماليات من كشف الحساب نفسه ليتطابق آخر رصيد مع البطاقة.
        $sales = round($statement->sum('debit'), 2);
        $received = round($statement->sum('credit'), 2);
        $balance = round($sales - $received, 2);

        return view('admin.crm.customers.show', compact(
            'customer', 'orders', 'receipts', 'statement', 'sales', 'received', 'balance',
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
            ->with('entry:id,number,entry_date,description')
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
}
