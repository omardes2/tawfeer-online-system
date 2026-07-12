<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الشحنات (ADR-027، ADR-010/BR-ORD-10). آلة حالات تتبّع فوق طلب مشحون؛ تُزامن حالة الطلب.
 * not_shipped → in_transit → out_for_delivery → delivered (+failed، وحالات تشغيلية delayed/customer_unavailable).
 * لا تكرّر خصم المخزون (يبقى في OrderService::ship — 2.6).
 */
class ShipmentService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ShippingCostResolver $costResolver,
    ) {}

    /**
     * إنشاء شحنة لطلب مشحون. يلتقط لقطة العنوان ويحلّ تكلفة الشحن ويلقّطها.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Order $order, array $data, int $year, ?float $manualCost = null): Shipment
    {
        if ($order->status !== 'shipped') {
            throw ValidationException::withMessages(['order' => __('لا يمكن إنشاء شحنة قبل شحن الطلب.')]);
        }

        // شحنة واحدة لكل طلب في هذه المرحلة (MVP)؛ يمنع ازدواج لقطة تكلفة الشحن على الطلب.
        if ($order->shipments()->exists()) {
            throw ValidationException::withMessages(['order' => __('للطلب شحنة قائمة بالفعل.')]);
        }

        return DB::transaction(function () use ($order, $data, $year, $manualCost) {
            $cost = $this->costResolver->resolve([
                'governorate_id' => $data['governorate_id'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'area_id' => $data['area_id'] ?? null,
                'shipping_zone_id' => $data['shipping_zone_id'] ?? null,
            ], $manualCost);

            $shipment = Shipment::create([
                'number' => NumberGenerator::next('shipments', 'number', 'SHP', $year),
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'status' => 'not_shipped',
                'carrier_name' => $data['carrier_name'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? $order->customer_name,
                'recipient_phone' => $data['recipient_phone'] ?? $order->customer_phone,
                'address_text' => $data['address_text'] ?? $order->shipping_address,
                'governorate_id' => $data['governorate_id'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'area_id' => $data['area_id'] ?? null,
                'shipping_zone_id' => $data['shipping_zone_id'] ?? null,
                'delivery_provider_id' => $data['delivery_provider_id'] ?? null,
                'shipping_cost' => $cost->cost,
                'cost_source' => $cost->source,
                'cost_currency' => $cost->currency,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->orders->applyShippingTotal($order, (float) $cost->cost);
            $this->recordEvent($shipment, null, 'not_shipped', 'system');

            return $shipment;
        });
    }

    /**
     * شحنة **مرتبطة** للمرتجع/الاستبدال (Phase 4.4 / ADR-040) — **دون تعديل الشحنة الأصلية**.
     * تتجاوز حارس «شحنة واحدة لكل طلب» وشرط حالة الطلب (الأصل قائم كما هو).
     *
     * @param  array<string, mixed>  $data
     */
    public function createLinkedShipment(Order $order, string $kind, array $data, int $year, ?Shipment $parent = null): Shipment
    {
        return DB::transaction(function () use ($order, $kind, $data, $year, $parent) {
            $shipment = Shipment::create([
                'number' => NumberGenerator::next('shipments', 'number', 'SHP', $year),
                'order_id' => $order->id,
                'parent_shipment_id' => $parent?->id,
                'kind' => $kind,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'status' => 'not_shipped',
                'carrier_name' => $data['carrier_name'] ?? $parent?->carrier_name,
                'tracking_number' => $data['tracking_number'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? $order->customer_name,
                'recipient_phone' => $data['recipient_phone'] ?? $order->customer_phone,
                'address_text' => $data['address_text'] ?? $order->shipping_address,
                'delivery_provider_id' => $data['delivery_provider_id'] ?? $parent?->delivery_provider_id,
                'shipping_cost' => 0,
                'cost_source' => 'linked',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->recordEvent($shipment, null, 'not_shipped', 'system', __('شحنة مرتبطة: :kind', ['kind' => $kind]));

            return $shipment;
        });
    }

    public function dispatch(Shipment $shipment): Shipment
    {
        $this->transition($shipment, ['not_shipped', 'preparing'], 'in_transit', function () use ($shipment) {
            $shipment->update(['dispatched_at' => now()]);
        });

        return $shipment;
    }

    public function outForDelivery(Shipment $shipment): Shipment
    {
        $this->transition($shipment, ['in_transit', 'delayed', 'customer_unavailable'], 'out_for_delivery', function () use ($shipment) {
            $this->orders->markOutForDelivery($shipment->order);
        });

        return $shipment;
    }

    public function markDelayed(Shipment $shipment, ?string $note = null): Shipment
    {
        $this->transition($shipment, ['in_transit', 'out_for_delivery'], 'delayed', function () use ($shipment, $note) {
            $shipment->increment('delivery_attempts');
            $this->orders->markDelayed($shipment->order, $note);
        }, $note);

        return $shipment;
    }

    public function markCustomerUnavailable(Shipment $shipment, ?string $note = null): Shipment
    {
        $this->transition($shipment, ['in_transit', 'out_for_delivery'], 'customer_unavailable', function () use ($shipment, $note) {
            $shipment->increment('delivery_attempts');
            $this->orders->markCustomerUnavailable($shipment->order, $note);
        }, $note);

        return $shipment;
    }

    public function deliver(Shipment $shipment): Shipment
    {
        $this->transition($shipment, ['in_transit', 'out_for_delivery', 'delayed', 'customer_unavailable'], 'delivered', function () use ($shipment) {
            $shipment->update(['delivered_at' => now()]);
            $this->orders->deliver($shipment->order);
        });

        return $shipment;
    }

    public function fail(Shipment $shipment, string $reason): Shipment
    {
        $this->transition($shipment, ['in_transit', 'out_for_delivery', 'delayed', 'customer_unavailable'], 'failed', function () use ($shipment, $reason) {
            $shipment->update(['failed_at' => now(), 'failure_reason' => $reason]);
            $this->orders->markDeliveryFailed($shipment->order, $reason);
        }, $reason);

        return $shipment;
    }

    /**
     * تجاوز تكلفة الشحن يدويًا (تُفحص الصلاحية في الطبقة الأعلى) — لقطة على الشحنة والطلب.
     */
    public function overrideCost(Shipment $shipment, float $cost): Shipment
    {
        return DB::transaction(function () use ($shipment, $cost) {
            $shipment->update(['shipping_cost' => $cost, 'cost_source' => 'manual']);
            $this->orders->applyShippingTotal($shipment->order, $cost);

            return $shipment;
        });
    }

    private function transition(Shipment $shipment, array $from, string $to, ?callable $effect = null, ?string $note = null): void
    {
        if (! in_array($shipment->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => __('العملية غير مسموحة على شحنة بحالة :status.', ['status' => $shipment->status]),
            ]);
        }

        DB::transaction(function () use ($shipment, $to, $effect, $note) {
            $fromStatus = $shipment->status;
            $shipment->loadMissing('order');

            if ($effect) {
                $effect();
            }

            $shipment->update(['status' => $to]);
            $this->recordEvent($shipment, $fromStatus, $to, 'manual', $note);
        });
    }

    private function recordEvent(Shipment $shipment, ?string $from, string $to, string $source, ?string $note = null): void
    {
        $shipment->events()->create([
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'note' => $note,
            'changed_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
