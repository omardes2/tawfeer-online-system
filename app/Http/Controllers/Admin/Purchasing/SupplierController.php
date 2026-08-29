<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierRequest;
use App\Http\Requests\Purchasing\UpdateSupplierRequest;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SupplierController extends Controller
{
    private const FILTERS = ['all', 'active', 'inactive', 'with_balance'];

    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Supplier::class);

        $filter = $request->query('filter');
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';

        // الرصيد من دفتر الأستاذ (بند فرعي بلا N+1) — لا من أعمدة الفواتير:
        // ذمّة المورد تتحرّك بفروق الصرف والدفعات على الحساب أيضًا، والدفتر وحده
        // يعرفها. راجع `SupplierService::ledgerBalance`.
        $query = Supplier::query()
            ->select('suppliers.*')
            ->selectRaw(SupplierService::ledgerBalanceExpression().' as ledger_balance');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('code', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('phone', 'like', $term));
        }

        match ($filter) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            // بند فرعي مترابط في WHERE (متوافق مع MySQL وSQLite، خلافًا لـ HAVING على
            // استعلام غير تجميعي) — وبالتعبير نفسه الذي يُعرض، فلا يظهر في «بأرصدة»
            // مورّدٌ رصيده صفر ولا يغيب عنها ذو رصيد.
            'with_balance' => $query->whereRaw(SupplierService::ledgerBalanceExpression().' <> 0'),
            default => null,
        };

        return view('admin.purchasing.suppliers.index', [
            'suppliers' => $query->latest('suppliers.id')->paginate(15)->withQueryString(),
            'search' => $request->input('search'),
            'filter' => $filter,
            'counts' => [
                'all' => Supplier::count(),
                'active' => Supplier::where('is_active', true)->count(),
                'inactive' => Supplier::where('is_active', false)->count(),
            ],
        ]);
    }

    public function show(Supplier $supplier): View
    {
        $this->authorize('view', $supplier);

        $invoices = PurchaseInvoice::where('supplier_id', $supplier->id)
            ->latest('id')->paginate(15);

        // إجمالي المشتريات = الفواتير المُرحّلة. المدفوعات = سندات الدفع المُرحّلة للمورد
        // (تشمل الدفعات المرتبطة بفاتورة والدفعات على الحساب معًا) — نفس مصدر كشف الحساب،
        // حتى يتطابق «الرصيد المتبقّي» في البطاقة مع آخر رصيد في كشف الحساب.
        $invoiced = (float) PurchaseInvoice::where('supplier_id', $supplier->id)
            ->where('status', 'posted')->sum('total');

        $paid = (float) FinancialVoucher::where('supplier_id', $supplier->id)
            ->where('kind', 'payment')->where('status', 'posted')->sum('amount');

        // الرصيد من الدفتر لا من طرحِ السندات من الفواتير — راجع
        // `SupplierService::ledgerBalance`.
        $balance = app(SupplierService::class)->ledgerBalance($supplier);

        // ما أطفأ الذمّة زيادةً على النقد الخارج: فروق الصرف عند السداد بالعملة
        // الأجنبية، والمرتجعات، وقيود التسوية. يُعرض صراحةً بدل أن يظهر فرقًا بين
        // البطاقات لا يُفسَّر — وهو بعينه ما جعل الشاشتين تختلفان.
        $adjustments = round((float) $supplier->opening_balance + $invoiced - $paid - $balance, 2);

        $payments = FinancialVoucher::where('supplier_id', $supplier->id)
            ->where('kind', 'payment')
            ->with('treasury:id,name')
            ->latest('voucher_date')->latest('id')->paginate(15, ['*'], 'payments_page');

        return view('admin.purchasing.suppliers.show', [
            'supplier' => $supplier->load('contacts'),
            'invoices' => $invoices,
            'payments' => $payments,
            'statement' => $this->buildStatement($supplier, (float) $supplier->opening_balance),
            'invoiced' => $invoiced,
            'paid' => $paid,
            'adjustments' => $adjustments,
            'balance' => $balance,
        ]);
    }

    /**
     * كشف حساب المورد: الفواتير المُرحّلة (تزيد المستحق) والدفعات (تُنقصه) مرتّبة زمنيًا برصيد متحرّك.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildStatement(Supplier $supplier, float $opening): Collection
    {
        $invoices = PurchaseInvoice::where('supplier_id', $supplier->id)
            ->where('status', 'posted')
            ->get(['id', 'number', 'invoice_date', 'total'])
            ->map(fn ($i) => [
                'date' => $i->invoice_date,
                'type' => 'invoice',
                'ref' => $i->number,
                'debit' => 0.0,          // نحن مدينون: تزيد ما نستحقه عليه
                'credit' => (float) $i->total,
                'model_id' => $i->id,
            ]);

        $payments = FinancialVoucher::where('supplier_id', $supplier->id)
            ->where('kind', 'payment')->where('status', 'posted')
            ->get(['id', 'number', 'voucher_date', 'amount'])
            ->map(fn ($v) => [
                'date' => $v->voucher_date,
                'type' => 'payment',
                'ref' => $v->number,
                'debit' => (float) $v->amount, // دفعنا: تُنقص المستحق
                'credit' => 0.0,
                'model_id' => $v->id,
            ]);

        $running = $opening;

        return $invoices->concat($payments)
            ->sortBy([['date', 'asc'], ['type', 'asc']])
            ->values()
            ->map(function (array $row) use (&$running) {
                $running += $row['credit'] - $row['debit'];
                $row['balance'] = round($running, 2);

                return $row;
            });
    }

    public function create(): View
    {
        $this->authorize('create', Supplier::class);

        return view('admin.purchasing.suppliers.form', [
            'supplier' => new Supplier,
            'suggestedCode' => $this->service->nextCode(),
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $this->service->create($request->safe()->except('contacts'), $request->validated('contacts', []));

        return redirect()->route('admin.purchasing.suppliers.index')->with('success', __('أُضيف المورد.'));
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorize('update', $supplier);

        return view('admin.purchasing.suppliers.form', ['supplier' => $supplier->load('contacts')]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $contacts = $request->has('contacts') ? $request->validated('contacts', []) : null;
        $this->service->update($supplier, $request->safe()->except('contacts'), $contacts);

        return redirect()->route('admin.purchasing.suppliers.index')->with('success', __('حُدّث المورد.'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        $this->service->delete($supplier);

        return redirect()->route('admin.purchasing.suppliers.index')->with('success', __('حُذف المورد.'));
    }
}
