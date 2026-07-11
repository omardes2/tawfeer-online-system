<?php

namespace App\Http\Resources\Purchasing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->uuid, 'name' => $this->supplier->name, 'code' => $this->supplier->code]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'name' => $this->warehouse->name]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => array_merge([
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'qty_ordered' => $i->qty_ordered,
                'qty_received' => $i->qty_received,
            ], $canViewCost ? [
                'unit_cost' => $i->unit_cost,
                'discount' => $i->discount,
                'line_total' => $i->line_total,
            ] : []))),
            $this->mergeWhen($canViewCost, fn () => [
                'subtotal' => $this->subtotal,
                'discount_total' => $this->discount_total,
                'total' => $this->total,
            ]),
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
