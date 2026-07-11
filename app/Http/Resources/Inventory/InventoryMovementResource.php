<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        return array_merge([
            'id' => $this->uuid,
            'type' => $this->type,
            'bucket' => $this->bucket,
            'qty' => $this->qty,
            'variant' => $this->whenLoaded('variant', fn () => ['id' => $this->variant->uuid, 'sku' => $this->variant->sku]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'name' => $this->warehouse->name]),
            'to_warehouse_id' => $this->to_warehouse_id,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ], $canViewCost ? [
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
        ] : []);
    }
}
