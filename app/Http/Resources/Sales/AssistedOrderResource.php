<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ملخّص الطلب المُساعد (Phase 4.1): القناة/الإسناد + بنود بلقطات + تغييرات السعر.
 * تُحجب لقطة التكلفة بلا صلاحية `pricing.view_cost` (ADR-013).
 */
class AssistedOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canCost = $request->user()?->can('pricing.view_cost') ?? false;

        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status,
            'channel' => $this->channel,
            'customer_id' => $this->customer_id,
            'assigned_to' => $this->assigned_to,
            'affiliate_id' => $this->affiliate_id,
            'total' => $this->total,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'variant_id' => $i->variant_id,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'retail_price_snapshot' => $i->retail_price_snapshot,
                'wholesale_cost_snapshot' => $canCost ? $i->wholesale_cost_snapshot : null,
                'discount' => $i->discount,
                'price_change_reason' => $i->price_change_reason,
                'price_approved' => ! is_null($i->price_approved_by),
            ])),
            'price_changes' => $this->whenLoaded('priceChanges', fn () => $this->priceChanges->map(fn ($c) => [
                'id' => $c->id,
                'variant_id' => $c->variant_id,
                'new_price' => $c->new_price,
                'original_retail' => $c->original_retail,
                'requires_approval' => $c->requires_approval,
                'status' => $c->status,
            ])),
        ];
    }
}
