<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        return array_merge([
            'id' => $this->id,
            'movement_type' => $this->movement_type,
            'bucket' => $this->bucket,
            'qty_change' => $this->qty_change,
            'balance_after' => $this->balance_after,
            'created_at' => $this->created_at?->toIso8601String(),
        ], $canViewCost ? [
            'unit_cost' => $this->unit_cost,
            'wac_after' => $this->wac_after,
            'value_change' => $this->value_change,
            'balance_value_after' => $this->balance_value_after,
        ] : []);
    }
}
