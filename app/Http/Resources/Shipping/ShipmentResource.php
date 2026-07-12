<?php

namespace App\Http\Resources\Shipping;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status,
            'order' => $this->whenLoaded('order', fn () => ['id' => $this->order->uuid, 'number' => $this->order->number]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'name' => $this->warehouse->name]),
            'carrier_name' => $this->carrier_name,
            'tracking_number' => $this->tracking_number,
            'recipient' => [
                'name' => $this->recipient_name,
                'phone' => $this->recipient_phone,
            ],
            'address_text' => $this->address_text,
            'governorate_id' => $this->governorate_id,
            'city_id' => $this->city_id,
            'area_id' => $this->area_id,
            'shipping_zone_id' => $this->shipping_zone_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->uuid, 'name' => $this->provider->name, 'code' => $this->provider->code] : null),
            'shipping_cost' => $this->shipping_cost,
            'cost_source' => $this->cost_source,
            'cost_currency' => $this->cost_currency,
            'delivery_attempts' => $this->delivery_attempts,
            'failure_reason' => $this->failure_reason,
            'notes' => $this->notes,
            'events' => $this->whenLoaded('events', fn () => ShipmentEventResource::collection($this->events)),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
