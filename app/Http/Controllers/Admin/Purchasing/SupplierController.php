<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierRequest;
use App\Http\Requests\Purchasing\UpdateSupplierRequest;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalLine;
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

        $statement = $this->buildStatement($supplier, (float) $supplier->opening_balance);
        $foreign = $this->foreignSummary($supplier, $paid, $balance);

        return view('admin.purchasing.suppliers.show', [
            'supplier' => $supplier->load('contacts'),
            'invoices' => $invoices,
            'payments' => $payments,
            'statement' => $statement,
            /*
                مجموع الفواتير لكل عملة على حدة — وهو الرقم الذي يُطابَق بكشف
                المورد. ولا يُجمع بعضها إلى بعض: ¥ و$ و₪ في رقمٍ واحد ليست مبلغًا.

                ويُحتسب هنا لا في القالب: الحساب منطقُ عرضٍ يُختبَر، والقالب يعرض.
            */
            'foreignTotals' => $statement->filter(fn (array $row) => ! empty($row['foreign']))
                ->groupBy('foreign_currency')
                ->map(fn ($rows) => round($rows->sum('foreign'), 2)),
            // رموز العملات للقالب: القالب لا يستدعي متحكّمًا آخر ليعرف رمز ¥.
            'currencySymbols' => PurchaseInvoiceController::CURRENCIES,
            'invoiced' => $invoiced,
            'paid' => $paid,
            'adjustments' => $adjustments,
            'balance' => $balance,
            'foreign' => $foreign,
        ]);
    }

    /**
     * البطاقات بعملات المورد إلى جانب الشيكل.
     *
     * ## رقمان مختلفان في طبيعتهما — ولا يُعرضان سواءً
     *
     * **المشتريات بعملة الفاتورة مجموعٌ حقيقي لا تحويل**: كل فاتورة تحمل قيمتها
     * بعملتها محفوظةً منذ إنشائها (`foreign_subtotal`)، فتُجمع كما هي. هذا رقمٌ
     * يُطابَق بكشف المورد سطرًا سطرًا.
     *
     * **أمّا المدفوعات والرصيد فتحويلٌ تقديري**: كلاهما مبلغٌ بالشيكل تراكم من
     * فواتير بأسعار صرفٍ مختلفة ومن دفعاتٍ بأسعار يومها، فلا سعرَ واحد يخصّه.
     * ويُحوَّلان بمعدّلٍ **موزون مشتقّ من فواتير هذا المورد نفسه** — لا من إعدادٍ
     * عام ولا من سعر اليوم: الدَّين تراكم من تلك الفواتير، فمعدّلها أقرب ما
     * يُقدَّر به. ويُوسَمان بـ«≈» فلا يُقرآن التزامًا دقيقًا.
     *
     * وبلا فاتورةٍ أجنبية واحدة تعود القائمة فارغة، فتبقى البطاقات بالشيكل وحده.
     *
     * @return array<string, mixed>
     */
    private function foreignSummary(Supplier $supplier, float $paid, float $balance): array
    {
        $invoices = PurchaseInvoice::where('supplier_id', $supplier->id)
            ->where('status', 'posted')
            ->where('foreign_subtotal', '>', 0)
            ->where('usd_rate', '>', 0)
            ->get(['currency', 'subtotal', 'foreign_subtotal', 'usd_rate']);

        if ($invoices->isEmpty()) {
            return [];
        }

        $base = round((float) $invoices->sum('subtotal'), 2);
        $foreignSum = round((float) $invoices->sum('foreign_subtotal'), 2);

        // مقام المعدّل الموزون: مجموع ما تساويه الفواتير بالدولار بأسعارها هي.
        $usdSum = round($invoices->sum(
            fn (PurchaseInvoice $i) => (float) $i->subtotal / (float) $i->usd_rate,
        ), 2);

        if ($base <= 0 || $foreignSum <= 0 || $usdSum <= 0) {
            return [];
        }

        $ilsPerForeign = $base / $foreignSum;
        $ilsPerUsd = $base / $usdSum;

        return [
            // العملة الغالبة على فواتير هذا المورد — لا تُخلط عملتان في رقم.
            'currency' => (string) $invoices->groupBy('currency')
                ->sortByDesc(fn ($rows) => $rows->sum('foreign_subtotal'))->keys()->first(),
            'invoiced_foreign' => $foreignSum,          // حقيقي
            'paid_usd' => round($paid / $ilsPerUsd, 2),         // تقديري
            'balance_usd' => round($balance / $ilsPerUsd, 2),   // تقديري
            'balance_foreign' => round($balance / $ilsPerForeign, 2), // تقديري
            'ils_per_usd' => round($ilsPerUsd, 4),
            'ils_per_foreign' => round($ilsPerForeign, 4),
        ];
    }

    /**
     * كشف الحساب من **دفتر الأستاذ** — كالرصيد تمامًا.
     *
     * بناؤه من الفواتير والسندات وحدها كان يُسقط ثلاثة أشياء:
     *
     * 1. **الرصيد الافتتاحي**: كان يدخل الرصيد المتحرّك صامتًا بلا سطر، فيبدأ
     *    الكشف من رقمٍ لا يُفسّره شيء — يقرأ المستخدم فاتورةً بـ13,208 فيقفز
     *    الرصيد إلى −136,088 ولا يجد لها سببًا.
     * 2. **فروق الصرف**: قيدٌ مستقلّ يُطفئ من الذمّة ما لم يخرج نقدًا، فيختلف
     *    آخرُ سطرٍ في الكشف عن بطاقة «الرصيد المتبقّي».
     * 3. **قيود التسوية اليدوية** على حساب المورد.
     *
     * والدفتر يحملها جميعًا، والبيان يُقرأ من وصف القيد نفسه — فلا يحتاج كلُّ
     * مصدرٍ جديدٍ تعديلًا هنا كي يظهر.
     */
    private function buildStatement(Supplier $supplier, float $opening): Collection
    {
        $account = $supplier->glAccount()->first();

        if (! $account) {
            return $this->buildStatementFromDocuments($supplier, $opening);
        }

        $running = 0.0;

        /*
            قيمة الفاتورة بعملتها بجانب أثرها بالشيكل: كشف المورد مكتوبٌ بعملته،
            فمن يطابق كشفَنا بكشفه يقارن رنمينبيًّا بشيكل. والربط بـ
            `journal_entry_id` لا بالوصف: القيد يعرف فاتورته صراحةً.

            وسطورُ الدفعات والافتتاحي وفروق الصرف لا فاتورة لها — تبقى فارغة،
            وليست نقصًا: لا قيمةَ بعملةٍ أجنبية لسطرٍ ليس فاتورة.
        */
        $foreignByEntry = PurchaseInvoice::where('supplier_id', $supplier->id)
            ->whereNotNull('journal_entry_id')
            ->get(['id', 'journal_entry_id', 'currency', 'subtotal', 'foreign_subtotal'])
            ->keyBy('journal_entry_id');

        return JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('je.status', 'posted')
            // **الافتتاحي أوّلًا مهما كان تاريخه.** قيده يُرحَّل بتاريخ اليوم الذي
            // أُدخل فيه لا بتاريخ بدء التعامل، فلو رُتّب زمنيًّا مع غيره سقط في
            // وسط الكشف بين فواتير حزيران وآب — ويُقرأ تسويةً طارئة لا نقطةَ
            // بداية. والرصيد الافتتاحي بحدّه هو ما قبل أوّل حركة.
            ->orderByRaw("CASE WHEN je.source = 'supplier_opening' THEN 0 ELSE 1 END")
            // ثم بالتاريخ فرقم القيد: قيدان في يومٍ واحد يُقرآن بترتيب تسجيلهما،
            // وإلا قفز الرصيد المتحرّك ورجع.
            ->orderBy('je.entry_date')->orderBy('je.id')->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->with('entry:id,number,entry_date,description,source')
            ->get()
            ->map(function (JournalLine $line) use (&$running, $foreignByEntry) {
                $running += (float) $line->credit - (float) $line->debit;
                $invoice = $foreignByEntry->get($line->entry->id);

                return [
                    'date' => $line->entry->entry_date,
                    'type' => $this->statementRowType($line->entry->source),
                    'ref' => $line->entry->description ?: $line->entry->number,
                    'debit' => round((float) $line->debit, 2),
                    'credit' => round((float) $line->credit, 2),
                    'foreign' => $invoice?->supplierDueForeign(),
                    'foreign_currency' => $invoice?->currency,
                    'model_id' => $line->entry->id,
                    'balance' => round($running, 2),
                ];
            });
    }

    /**
     * وسمُ السطر من مصدر القيد. ما لا يُعرف يُوسَم «حركة» بدل أن يُنسب خطأً إلى
     * فاتورةٍ أو دفعة — والوصف تحته يقول ما هو.
     */
    private function statementRowType(?string $source): string
    {
        return match ($source) {
            'purchase_invoice' => 'invoice',
            'voucher' => 'payment',
            'purchase_invoice_fx' => 'fx',
            'opening_balance', 'supplier_opening' => 'opening',
            default => 'entry',
        };
    }

    /** الاحتياط للمورّد بلا حساب فرعيّ: البناء من المستندات كما كان. */
    private function buildStatementFromDocuments(Supplier $supplier, float $opening): Collection
    {
        $invoices = PurchaseInvoice::where('supplier_id', $supplier->id)
            ->where('status', 'posted')
            ->get(['id', 'number', 'invoice_date', 'total', 'currency', 'subtotal', 'foreign_subtotal'])
            ->map(fn ($i) => [
                'date' => $i->invoice_date,
                'type' => 'invoice',
                'ref' => $i->number,
                'debit' => 0.0,          // نحن مدينون: تزيد ما نستحقه عليه
                'credit' => (float) $i->total,
                'foreign' => $i->supplierDueForeign(),
                'foreign_currency' => $i->currency,
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
                'foreign' => null,             // الدفعة ليست فاتورة — لا قيمة بعملةٍ أجنبية.
                'foreign_currency' => null,
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
