<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseInvoiceRequest;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * فواتير الموردين/الشراء (REQUIREMENTS §2.5). لا منطق أعمال هنا — كله في الخدمة.
 */
class PurchaseInvoiceController extends Controller
{
    private const STATUSES = ['draft', 'approved', 'posted', 'cancelled', 'reversed'];

    /** عملات الشراء المتاحة ورموزها — الأساسية أولًا. */
    public const CURRENCIES = ['ILS' => '₪', 'USD' => '$', 'CNY' => '¥', 'EUR' => '€'];

    private const EMPTY_ROW = [
        'is_new' => false, 'variant_id' => '', 'new_name' => '', 'sell_price' => 0,
        'description' => '', 'qty' => 1, 'unit_cost' => 0, 'tax_rate' => 0,
        'unit_price_foreign' => 0, 'cbm_per_unit' => 0, 'landed_unit_cost' => 0, 'landed_is_manual' => false,
    ];

    public function __construct(
        private readonly PurchaseInvoiceService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('purchasing.invoices.view');

        $status = $request->query('status');
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        $query = PurchaseInvoice::with('supplier')->latest('id');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return view('admin.purchasing.invoices.index', [
            'invoices' => $query->paginate(20)->withQueryString(),
            'statuses' => self::STATUSES,
            'activeStatus' => $status,
            'statusCounts' => PurchaseInvoice::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'totalCount' => PurchaseInvoice::count(),
            'outstanding' => (float) PurchaseInvoice::posted()->whereIn('payment_status', ['unpaid', 'partial'])
                ->selectRaw('SUM(total - amount_paid) as d')->value('d'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('purchasing.invoices.create');

        return view('admin.purchasing.invoices.form', $this->formViewData(null));
    }

    /**
     * بيانات نموذج الإنشاء/التعديل — تُمرَّر كمتغيّرات عرض (لا @php علوية) حتى تتوفّر داخل
     * نطاق مكوّنات التخطيط (slots).
     *
     * @return array<string, mixed>
     */
    private function formViewData(?PurchaseInvoice $invoice): array
    {
        $rows = $invoice
            ? $invoice->items->map(fn ($it) => [
                'is_new' => (bool) (! $it->variant_id && $it->new_product_name),
                'variant_id' => (string) ($it->variant_id ?? ''),
                'new_name' => $it->new_product_name ?? '',
                'sell_price' => $it->new_product_sell_price !== null ? (float) $it->new_product_sell_price : 0,
                'description' => $it->description ?? '',
                'qty' => (float) $it->qty,
                'unit_cost' => (float) $it->unit_cost,
                'tax_rate' => (float) $it->tax_rate,
                'unit_price_foreign' => (float) $it->unit_price_foreign,
                'cbm_per_unit' => (float) $it->cbm_per_unit,
                'landed_unit_cost' => (float) $it->landed_unit_cost,
                'landed_is_manual' => (bool) $it->landed_is_manual,
            ])->values()->all()
            : [];
        if (empty($rows)) {
            $rows = [self::EMPTY_ROW];
        }

        $variants = ProductVariant::with('product:id,name,cbm')->orderBy('id')->get();

        return [
            'invoice' => $invoice,
            'editing' => (bool) $invoice,
            'initialRows' => $rows,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'variants' => $variants,
            // حجم كل متغيّر بالمتر المكعّب — تملأ به الواجهة خانة الـCBM فور اختيار
            // الصنف، فلا يُعاد إدخال ما هو مسجَّل في كرت الصنف.
            'variantCbm' => $variants->mapWithKeys(fn (ProductVariant $v) => [
                (string) $v->id => (float) ($v->cbm ?? $v->product?->cbm ?? 0),
            ])->all(),
            'baseCurrency' => strtoupper((string) config('app.currency', 'ILS')),
            'currencies' => self::CURRENCIES,
        ];
    }

    /** الحفظ = إنشاء + ترحيل محاسبي فوري (بلا مسودّة/اعتماد منفصلين — قرار إداري). */
    public function store(StorePurchaseInvoiceRequest $request): RedirectResponse
    {
        try {
            $invoice = $this->service->createAndPost(
                $request->safe()->except('items'),
                $this->mapItems($request->validated('items')),
            );
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.purchasing.invoices.show', $invoice)
            ->with('success', __('حُفظت الفاتورة ورُحّلت محاسبيًا.'));
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        $this->authorize('purchasing.invoices.create');
        // المُرحّلة قابلة للتعديل ما لم يُسدَّد منها شيء (يُعكس أثر المخزون ويُحدَّث القيد).
        abort_unless($this->isEditable($invoice), 403, __('لا يمكن تعديل فاتورة بهذه الحالة.'));

        return view('admin.purchasing.invoices.form', $this->formViewData($invoice->load('items.variant.product')));
    }

    public function update(StorePurchaseInvoiceRequest $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.create');
        abort_unless($this->isEditable($invoice), 403, __('لا يمكن تعديل فاتورة بهذه الحالة.'));

        try {
            $this->service->updatePosted($invoice, $request->safe()->except('items'), $this->mapItems($request->validated('items')));
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.purchasing.invoices.show', $invoice)
            ->with('success', __('حُدّثت الفاتورة وقيدها المحاسبي وأثرها المخزوني.'));
    }

    /**
     * يُحوّل بنود الطلب: الصنف الجديد يُمرَّر باسمه/سعره المقترح ليُنشأ المنتج **عند الترحيل**
     * فقط (لا في المسودّة) — لا تُنشأ منتجات ولا يدخل مخزون قبل الترحيل.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mapItems(array $items): array
    {
        return collect($items)->map(function (array $it) {
            if (empty($it['variant_id']) && ! empty($it['new_name'])) {
                $it['new_product_name'] = $it['new_name'];
                $it['new_product_sell_price'] = $it['sell_price'] ?? null;
            }
            unset($it['new_name'], $it['sell_price']);

            return $it;
        })->all();
    }

    public function show(PurchaseInvoice $invoice): View
    {
        $this->authorize('purchasing.invoices.view');

        return view('admin.purchasing.invoices.show', [
            'invoice' => $invoice->load(['items.variant.product', 'supplier', 'journalEntry']),
            'treasuries' => Treasury::where('is_active', true)->orderBy('name')->get(),
            'currencies' => self::CURRENCIES,
        ]);
    }

    public function approve(PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.approve');

        return $this->guard(fn () => $this->service->approve($invoice), __('اعتُمدت الفاتورة.'));
    }

    public function post(PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.post');

        return $this->guard(fn () => $this->service->post($invoice), __('رُحّلت الفاتورة محاسبيًا.'));
    }

    public function pay(Request $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.pay');

        $data = $request->validate([
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'voucher_date' => ['nullable', 'date'],
        ]);

        return $this->guard(
            fn () => $this->service->pay($invoice, (int) $data['treasury_id'], (float) $data['amount'], $data['voucher_date'] ?? null),
            __('سُجّلت الدفعة.'),
        );
    }

    public function reverse(PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.post');

        return $this->guard(fn () => $this->service->reverse($invoice), __('عُكست الفاتورة.'));
    }

    public function cancel(PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.approve');

        return $this->guard(fn () => $this->service->cancel($invoice), __('أُلغيت الفاتورة.'));
    }

    public function destroy(PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.delete');

        if (! self::isDeletable($invoice)) {
            return back()->with('error', __('الفاتورة ملغاة/معكوسة — لا أثر لها ليُعكس.'));
        }

        try {
            // يعكس الدفعات (يعيد النقد للخزينة)، يسحب البضاعة، ويعكس قيد الشراء.
            $this->service->deletePosted($invoice);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.purchasing.invoices.index')
            ->with('success', __('حُذفت الفاتورة: عُكست دفعاتها وقيدها المحاسبي وسُحبت بضاعتها من المخزون.'));
    }

    /** الفاتورة قابلة للتعديل ما لم تكن ملغاة/معكوسة أو سُدّد منها شيء. */
    public static function isEditable(PurchaseInvoice $invoice): bool
    {
        return ! in_array($invoice->status, ['cancelled', 'reversed'], true)
            && (float) $invoice->amount_paid <= 0;
    }

    /**
     * الحذف متاح حتى للفاتورة المسدَّدة — الخدمة تعكس دفعاتها أولًا (يعود النقد للخزينة)
     * ثم تسحب المخزون وتعكس قيد الشراء. يبقى الملغى/المعكوس خارج النطاق (لا أثر ليُعكس).
     */
    public static function isDeletable(PurchaseInvoice $invoice): bool
    {
        return ! in_array($invoice->status, ['cancelled', 'reversed'], true);
    }

    /** ينفّذ إجراء الخدمة ويحوّل أخطاء التحقّق إلى رسالة راجعة. */
    private function guard(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $success);
    }
}
