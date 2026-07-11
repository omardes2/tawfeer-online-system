<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductTag;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

class ProductTagService
{
    public function create(array $data): ProductTag
    {
        return DB::transaction(function () use ($data) {
            $source = filled($data['slug'] ?? null) ? $data['slug'] : $data['name'];
            $data['slug'] = SlugGenerator::make(ProductTag::class, $source);

            return ProductTag::create($data);
        });
    }

    public function update(ProductTag $tag, array $data): ProductTag
    {
        return DB::transaction(function () use ($tag, $data) {
            if (isset($data['name']) || isset($data['slug'])) {
                $source = filled($data['slug'] ?? null) ? $data['slug'] : ($data['name'] ?? $tag->name);
                $data['slug'] = SlugGenerator::make(ProductTag::class, $source, $tag->id);
            }

            $tag->update($data);

            return $tag;
        });
    }

    public function delete(ProductTag $tag): void
    {
        $tag->delete();
    }
}
