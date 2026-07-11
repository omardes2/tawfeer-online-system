<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductAttribute;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

class ProductAttributeService
{
    public function create(array $data): ProductAttribute
    {
        return DB::transaction(function () use ($data) {
            $source = filled($data['slug'] ?? null) ? $data['slug'] : $data['name'];
            $data['slug'] = SlugGenerator::make(ProductAttribute::class, $source);

            return ProductAttribute::create($data);
        });
    }

    public function update(ProductAttribute $attribute, array $data): ProductAttribute
    {
        return DB::transaction(function () use ($attribute, $data) {
            if (isset($data['name']) || isset($data['slug'])) {
                $source = filled($data['slug'] ?? null) ? $data['slug'] : ($data['name'] ?? $attribute->name);
                $data['slug'] = SlugGenerator::make(ProductAttribute::class, $source, $attribute->id);
            }

            $attribute->update($data);

            return $attribute;
        });
    }

    public function delete(ProductAttribute $attribute): void
    {
        // القيم تُحذف تعاقبيًا عبر قيد قاعدة البيانات (CASCADE).
        $attribute->delete();
    }
}
