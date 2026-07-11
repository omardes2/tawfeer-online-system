<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'type' => $this->type,
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'name' => $this->warehouse->name]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => array_merge([
                'variant_id' => $i->variant_id,
                'qty_before' => $i->qty_before,
                'qty_counted' => $i->qty_counted,
                'qty_diff' => $i->qty_diff,
                'note' => $i->note,
            ], $canViewCost ? ['unit_cost' => $i->unit_cost] : []))),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
