<?php

namespace App\Http\Controllers\Admin\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RefundPaymentRequest;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Sales\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', Payment::class);

        return view('admin.payments.index', [
            'payments' => Payment::with(['order', 'method'])->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Payment::class);

        $order = null;
        if ($request->filled('order')) {
            $order = Order::where('uuid', $request->string('order'))->firstOrFail();
        }

        return view('admin.payments.form', [
            'order' => $order,
            'payableOrders' => Order::whereNotIn('payment_status', ['paid', 'refunded'])
                ->whereNotIn('status', ['cancelled', 'draft'])->latest('id')->limit(100)->get(),
            'methods' => PaymentMethod::active()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $this->authorize('create', Payment::class);

        $order = Order::where('uuid', $request->validated('order'))->firstOrFail();
        $method = PaymentMethod::where('code', $request->validated('method'))->firstOrFail();

        try {
            $payment = $this->service->initiate($order, $method, (float) $request->validated('amount'), (int) now()->year, [
                'currency' => $request->validated('currency'),
                'notes' => $request->validated('notes'),
            ]);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.payments.show', $payment)->with('success', __('أُنشئت الدفعة.'));
    }

    public function show(Payment $payment): View
    {
        $this->authorize('view', $payment);

        return view('admin.payments.show', [
            'payment' => $payment->load(['order', 'method', 'transactions']),
        ]);
    }

    public function capture(Payment $payment): RedirectResponse
    {
        $this->authorize('capture', $payment);

        return $this->guard(fn () => $this->service->capture($payment), __('تم تحصيل الدفعة.'));
    }

    public function refund(RefundPaymentRequest $request, Payment $payment): RedirectResponse
    {
        $this->authorize('refund', $payment);

        $amount = $request->filled('amount') ? (float) $request->validated('amount') : null;

        return $this->guard(fn () => $this->service->refund($payment, $amount), __('تمّ الاسترداد.'));
    }

    private function guard(callable $fn, string $success): RedirectResponse
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
