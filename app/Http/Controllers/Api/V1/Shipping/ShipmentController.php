<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\FailShipmentRequest;
use App\Http\Requests\Shipping\OverrideCostRequest;
use App\Http\Requests\Shipping\StoreShipmentRequest;
use App\Http\Resources\Shipping\ShipmentResource;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\ShippingZone;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);

        $query = Shipment::query()->with(['order', 'warehouse']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return ShipmentResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function show(Shipment $shipment): ShipmentResource
    {
        $this->authorize('view', $shipment);

        return new ShipmentResource($shipment->load(['order', 'warehouse', 'provider', 'events.changedBy']));
    }

    public function store(StoreShipmentRequest $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $order = Order::where('uuid', $request->validated('order'))->firstOrFail();

        $manualCost = null;
        if ($request->filled('shipping_cost')) {
            abort_unless($request->user()->can('shipping.override_cost'), 403, __('لا تملك صلاحية تحديد تكلفة الشحن يدويًا.'));
            $manualCost = (float) $request->validated('shipping_cost');
        }

        $shipment = $this->service->create($order, $this->header($request), (int) now()->year, $manualCost);

        return (new ShipmentResource($shipment->load(['order', 'warehouse', 'provider', 'events'])))->response()->setStatusCode(201);
    }

    public function dispatchShipment(Shipment $shipment): ShipmentResource
    {
        $this->authorize('dispatchShipment', $shipment);

        return $this->respond($this->service->dispatch($shipment));
    }

    public function outForDelivery(Shipment $shipment): ShipmentResource
    {
        $this->authorize('dispatchShipment', $shipment);

        return $this->respond($this->service->outForDelivery($shipment));
    }

    public function delay(Request $request, Shipment $shipment): ShipmentResource
    {
        $this->authorize('dispatchShipment', $shipment);

        return $this->respond($this->service->markDelayed($shipment, $request->input('note')));
    }

    public function customerUnavailable(Request $request, Shipment $shipment): ShipmentResource
    {
        $this->authorize('dispatchShipment', $shipment);

        return $this->respond($this->service->markCustomerUnavailable($shipment, $request->input('note')));
    }

    public function deliver(Shipment $shipment): ShipmentResource
    {
        $this->authorize('deliver', $shipment);

        return $this->respond($this->service->deliver($shipment));
    }

    public function fail(FailShipmentRequest $request, Shipment $shipment): ShipmentResource
    {
        $this->authorize('fail', $shipment);

        return $this->respond($this->service->fail($shipment, $request->validated('reason')));
    }

    public function overrideCost(OverrideCostRequest $request, Shipment $shipment): ShipmentResource
    {
        $this->authorize('overrideCost', $shipment);

        return $this->respond($this->service->overrideCost($shipment, (float) $request->validated('shipping_cost')));
    }

    private function header(StoreShipmentRequest $request): array
    {
        $zoneId = $request->filled('shipping_zone')
            ? ShippingZone::where('uuid', $request->validated('shipping_zone'))->value('id')
            : null;
        $providerId = $request->filled('delivery_provider')
            ? DeliveryProvider::where('uuid', $request->validated('delivery_provider'))->value('id')
            : null;

        return [
            'carrier_name' => $request->validated('carrier_name'),
            'tracking_number' => $request->validated('tracking_number'),
            'recipient_name' => $request->validated('recipient_name'),
            'recipient_phone' => $request->validated('recipient_phone'),
            'address_text' => $request->validated('address_text'),
            'governorate_id' => $request->validated('governorate_id'),
            'city_id' => $request->validated('city_id'),
            'area_id' => $request->validated('area_id'),
            'shipping_zone_id' => $zoneId,
            'delivery_provider_id' => $providerId,
            'notes' => $request->validated('notes'),
        ];
    }

    private function respond(Shipment $shipment): ShipmentResource
    {
        return new ShipmentResource($shipment->load(['order', 'warehouse', 'provider', 'events.changedBy']));
    }
}
