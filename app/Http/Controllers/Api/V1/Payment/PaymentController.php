<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RefundPaymentRequest;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\Payment\PaymentResource;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Sales\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with(['order', 'method']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('order')) {
            $orderId = Order::where('uuid', $request->string('order'))->value('id');
            $query->where('order_id', $orderId);
        }

        return PaymentResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment->load(['order', 'method', 'transactions']));
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $order = Order::where('uuid', $request->validated('order'))->firstOrFail();
        $method = PaymentMethod::where('code', $request->validated('method'))->firstOrFail();

        $payment = $this->service->initiate($order, $method, (float) $request->validated('amount'), (int) now()->year, [
            'currency' => $request->validated('currency'),
            'notes' => $request->validated('notes'),
        ]);

        return (new PaymentResource($payment->load(['order', 'method', 'transactions'])))->response()->setStatusCode(201);
    }

    public function capture(Payment $payment): PaymentResource
    {
        $this->authorize('capture', $payment);

        return new PaymentResource($this->service->capture($payment)->load(['order', 'method', 'transactions']));
    }

    public function refund(RefundPaymentRequest $request, Payment $payment): PaymentResource
    {
        $this->authorize('refund', $payment);

        $amount = $request->filled('amount') ? (float) $request->validated('amount') : null;

        return new PaymentResource($this->service->refund($payment, $amount)->load(['order', 'method', 'transactions']));
    }

    public function callback(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('capture', $payment);

        return new PaymentResource($this->service->handleCallback($payment, $request->all())->load(['order', 'method', 'transactions']));
    }
}
