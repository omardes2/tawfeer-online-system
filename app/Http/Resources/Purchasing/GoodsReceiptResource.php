<?php

namespace App\Http\Resources\Purchasing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? ['id' => $this->purchaseOrder->uuid, 'number' => $this->purchaseOrder->number] : null),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'name' => $this->warehouse->name]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => array_merge([
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'purchase_order_item_id' => $i->purchase_order_item_id,
                'qty_received' => $i->qty_received,
            ], $canViewCost ? [
                'unit_cost' => $i->unit_cost,
                'landed_unit_cost' => $i->landed_unit_cost,
            ] : []))),
            $this->mergeWhen($canViewCost, fn () => ['additional_cost' => $this->additional_cost]),
            'notes' => $this->notes,
            'received_at' => $this->received_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
