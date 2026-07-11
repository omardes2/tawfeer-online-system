<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

/**
 * منطق أعمال المنتجات (خارج المتحكمات): توليد slug، اشتقاق is_active من status،
 * مزامنة الوسوم والسمات — كله داخل معاملة ذرّية (المبدأ 7).
 */
class ProductService
{
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            [$tagIds, $attributeIds, $attributes] = $this->extractRelations($data);

            $attributes['slug'] = $this->resolveSlug($attributes);
            $attributes['is_active'] = ($attributes['status'] ?? 'draft') === 'active';

            $product = Product::create($attributes);
            $product->tags()->sync($tagIds);
            $product->attributes()->sync($attributeIds);

            return $product;
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            [$tagIds, $attributeIds, $attributes] = $this->extractRelations($data);

            if (isset($attributes['name']) || isset($attributes['slug'])) {
                $attributes['slug'] = $this->resolveSlug($attributes, $product);
            }

            if (array_key_exists('status', $attributes)) {
                $attributes['is_active'] = $attributes['status'] === 'active';
            }

            $product->update($attributes);

            if ($tagIds !== null) {
                $product->tags()->sync($tagIds);
            }

            if ($attributeIds !== null) {
                $product->attributes()->sync($attributeIds);
            }

            return $product;
        });
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * يفصل مصفوفات العلاقات عن سمات النموذج.
     *
     * @return array{0: ?array, 1: ?array, 2: array}
     */
    private function extractRelations(array $data): array
    {
        $tagIds = array_key_exists('tag_ids', $data) ? (array) $data['tag_ids'] : null;
        $attributeIds = array_key_exists('attribute_ids', $data) ? (array) $data['attribute_ids'] : null;

        unset($data['tag_ids'], $data['attribute_ids']);

        return [$tagIds, $attributeIds, $data];
    }

    private function resolveSlug(array $data, ?Product $product = null): string
    {
        $source = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($data['name'] ?? $product?->name ?? '');

        return SlugGenerator::make(Product::class, $source, $product?->id);
    }
}
