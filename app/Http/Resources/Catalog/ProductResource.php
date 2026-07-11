<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        return array_merge([
            'id' => $this->uuid,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'type' => $this->type,
            'short_description' => $this->short_description,
            'short_description_en' => $this->short_description_en,
            'description' => $this->description,
            'description_en' => $this->description_en,

            'status' => $this->status,
            'visibility' => $this->visibility,
            'is_featured' => $this->is_featured,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'track_inventory' => $this->track_inventory,
            'weight' => $this->weight,

            // أسعار البيع (تُدار في مرحلة تسعير لاحقة؛ تظهر للقراءة)
            'retail_price' => $this->retail_price,
            'wholesale_price' => $this->wholesale_price,
            'marketer_price' => $this->marketer_price,
            'promo_price' => $this->promo_price,

            'seo' => [
                'meta_title' => $this->meta_title,
                'meta_description' => $this->meta_description,
                'meta_keywords' => $this->meta_keywords,
            ],
            'search_keywords' => $this->search_keywords,

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->uuid,
                'name' => $this->category->name,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->uuid,
                'name' => $this->brand->name,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'code' => $this->unit->code,
            ]),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug,
            ])),
            'attributes' => $this->whenLoaded('attributes', fn () => $this->attributes->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'type' => $a->type,
            ])),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ],
            // حقول التكلفة/الربح محجوبة بلا صلاحية (ADR-013)
            $canViewCost ? [
                'cost_price' => $this->cost_price,
                'average_cost' => $this->average_cost,
                'min_price' => $this->min_price,
                'target_margin' => $this->target_margin,
            ] : [],
        );
    }
}
