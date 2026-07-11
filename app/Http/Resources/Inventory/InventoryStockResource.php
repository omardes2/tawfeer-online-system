<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        return array_merge([
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->uuid,
                'sku' => $this->variant->sku,
                'name' => $this->variant->name,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->uuid,
                'name' => $this->warehouse->name,
            ]),
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'damaged' => $this->damaged,
            'returned_pending' => $this->returned_pending,
            'in_transit' => $this->in_transit,
            'reorder_level' => $this->reorder_level,
            'last_movement_at' => $this->last_movement_at?->toIso8601String(),
        ], $canViewCost ? [
            'average_cost' => $this->average_cost,
            'cost_price' => $this->cost_price,
            'stock_value' => (float) $this->on_hand * (float) $this->average_cost,
        ] : []);
    }
}
