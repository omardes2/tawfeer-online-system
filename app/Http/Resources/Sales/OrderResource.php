<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'channel' => $this->channel,
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
            ],
            'shipping_address' => $this->shipping_address,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'name' => $this->warehouse->name]),
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->uuid, 'name' => $this->assignee->name] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'discount' => $i->discount,
                'line_total' => $i->line_total,
                'qty_reserved' => $i->qty_reserved,
                'qty_shipped' => $i->qty_shipped,
            ])),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'shipping_total' => $this->shipping_total,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'history' => $this->whenLoaded('statusHistory', fn () => OrderStatusHistoryResource::collection($this->statusHistory)),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'reserved_at' => $this->reserved_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
