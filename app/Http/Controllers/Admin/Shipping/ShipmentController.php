<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\FailShipmentRequest;
use App\Http\Requests\Shipping\StoreShipmentRequest;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', Shipment::class);

        return view('admin.shipping.shipments.index', [
            'shipments' => Shipment::with(['order', 'warehouse'])->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Shipment::class);

        $order = null;
        if ($request->filled('order')) {
            $order = Order::where('uuid', $request->string('order'))->firstOrFail();
        }

        return view('admin.shipping.shipments.form', [
            'order' => $order,
            'shippableOrders' => Order::where('status', 'shipped')
                ->whereDoesntHave('shipments')->latest('id')->get(),
            'providers' => DeliveryProvider::active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreShipmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Shipment::class);

        $order = Order::where('uuid', $request->validated('order'))->firstOrFail();

        $manualCost = null;
        if ($request->filled('shipping_cost')) {
            if (! $request->user()->can('shipping.override_cost')) {
                return back()->withInput()->with('error', __('لا تملك صلاحية تحديد تكلفة الشحن يدويًا.'));
            }
            $manualCost = (float) $request->validated('shipping_cost');
        }

        $provider = $request->filled('delivery_provider')
            ? DeliveryProvider::where('uuid', $request->validated('delivery_provider'))->value('id')
            : null;

        try {
            $shipment = $this->service->create($order, [
                'carrier_name' => $request->validated('carrier_name'),
                'tracking_number' => $request->validated('tracking_number'),
                'delivery_provider_id' => $provider,
                'notes' => $request->validated('notes'),
            ], (int) now()->year, $manualCost);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.shipping.shipments.show', $shipment)->with('success', __('أُنشئت الشحنة.'));
    }

    public function show(Shipment $shipment): View
    {
        $this->authorize('view', $shipment);

        return view('admin.shipping.shipments.show', [
            'shipment' => $shipment->load(['order', 'warehouse', 'provider', 'events.changedBy']),
        ]);
    }

    public function dispatchShipment(Shipment $shipment): RedirectResponse
    {
        $this->authorize('dispatchShipment', $shipment);

        return $this->guard(fn () => $this->service->dispatch($shipment), __('تم إرسال الشحنة.'));
    }

    public function outForDelivery(Shipment $shipment): RedirectResponse
    {
        $this->authorize('dispatchShipment', $shipment);

        return $this->guard(fn () => $this->service->outForDelivery($shipment), __('الشحنة خرجت للتوصيل.'));
    }

    public function deliver(Shipment $shipment): RedirectResponse
    {
        $this->authorize('deliver', $shipment);

        return $this->guard(fn () => $this->service->deliver($shipment), __('تم تسليم الشحنة.'));
    }

    public function fail(FailShipmentRequest $request, Shipment $shipment): RedirectResponse
    {
        $this->authorize('fail', $shipment);

        return $this->guard(fn () => $this->service->fail($shipment, $request->validated('reason')), __('سُجّل فشل التسليم.'));
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
