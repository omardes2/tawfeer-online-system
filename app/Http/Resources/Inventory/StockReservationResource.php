<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'qty' => $this->qty,
            'status' => $this->status,
            'variant' => $this->whenLoaded('variant', fn () => ['id' => $this->variant->uuid, 'sku' => $this->variant->sku]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'name' => $this->warehouse->name]),
            'reserved_at' => $this->reserved_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
