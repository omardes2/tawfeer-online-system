<?php

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'variant_id' => $i->variant?->uuid,
                'sku' => $i->variant?->sku,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'line_total' => round((float) $i->qty * (float) $i->unit_price, 2),
            ])),
            'subtotal' => round($this->subtotal(), 2),
            'item_count' => $this->whenLoaded('items', fn () => $this->items->count()),
        ];
    }
}
