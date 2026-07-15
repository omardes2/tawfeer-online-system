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
            ])->values()->all()
            : [];
        if (empty($rows)) {
            $rows = [['is_new' => false, 'variant_id' => '', 'new_name' => '', 'sell_price' => 0, 'description' => '', 'qty' => 1, 'unit_cost' => 0, 'tax_rate' => 0]];
        }

        return [
            'invoice' => $invoice,
            'editing' => (bool) $invoice,
            'initialRows' => $rows,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'variants' => ProductVariant::with('product:id,name')->orderBy('id')->get(),
        ];
    }

    public function store(StorePurchaseInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->service->create($request->safe()->except('items'), $this->mapItems($request->validated('items')));

        return redirect()->route('admin.purchasing.invoices.show', $invoice)->with('success', __('أُنشئت فاتورة الشراء.'));
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        $this->authorize('purchasing.invoices.create');
        abort_unless(in_array($invoice->status, ['draft', 'approved'], true), 403, __('لا يمكن تعديل فاتورة بهذه الحالة.'));

        return view('admin.purchasing.invoices.form', $this->formViewData($invoice->load('items.variant.product')));
    }

    public function update(StorePurchaseInvoiceRequest $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $this->authorize('purchasing.invoices.create');
        abort_unless(in_array($invoice->status, ['draft', 'approved'], true), 403, __('لا يمكن تعديل فاتورة بهذه الحالة.'));

        try {
            $this->service->update($invoice, $request->safe()->except('items'), $this->mapItems($request->validated('items')));
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.purchasing.invoices.show', $invoice)->with('success', __('حُدّثت الفاتورة.'));
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

        if (! in_array($invoice->status, ['draft', 'cancelled'], true)) {
            return back()->with('error', __('لا يمكن حذف فاتورة مُرحّلة — استخدم العكس.'));
        }
        $invoice->delete();

        return redirect()->route('admin.purchasing.invoices.index')->with('success', __('حُذفت الفاتورة.'));
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
