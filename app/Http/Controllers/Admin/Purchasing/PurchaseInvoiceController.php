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

    public function __construct(private readonly PurchaseInvoiceService $service) {}

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

        return view('admin.purchasing.invoices.form', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'variants' => ProductVariant::with('product:id,name')->orderBy('id')->get(),
        ]);
    }

    public function store(StorePurchaseInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->service->create($request->safe()->except('items'), $request->validated('items'));

        return redirect()->route('admin.purchasing.invoices.show', $invoice)->with('success', __('أُنشئت فاتورة الشراء.'));
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
