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
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($i) {
                $product = $i->variant?->product;

                // خيارات المتغيّر (مقاس/لون) تُلحق بالاسم، وإلّا بدا مقاسان من
                // المنتج نفسه بندين متطابقين في السلة.
                $options = $i->variant?->relationLoaded('attributeValues')
                    ? $i->variant->attributeValues->map(fn ($v) => $v->label ?: $v->value)->filter()->implode(' · ')
                    : '';

                return [
                    'variant_id' => $i->variant?->uuid,
                    'sku' => $i->variant?->sku,
                    // `name` و`image` إضافتان لا تكسران أي مستهلك قائم: السلة كانت
                    // تعرض رمز المنتج (SKU) للزبون لغياب الاسم في الحمولة.
                    'name' => $product?->name,
                    'options' => $options !== '' ? $options : null,
                    'image' => $product?->primaryImage?->url(),
                    'qty' => $i->qty,
                    'unit_price' => $i->unit_price,
                    'line_total' => round((float) $i->qty * (float) $i->unit_price, 2),
                ];
            })),
            'subtotal' => round($this->subtotal(), 2),
            'item_count' => $this->whenLoaded('items', fn () => $this->items->count()),
        ];
    }
}
