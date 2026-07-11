<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Brand;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

class BrandService
{
    public function create(array $data): Brand
    {
        return DB::transaction(function () use ($data) {
            $source = filled($data['slug'] ?? null) ? $data['slug'] : $data['name'];
            $data['slug'] = SlugGenerator::make(Brand::class, $source);

            return Brand::create($data);
        });
    }

    public function update(Brand $brand, array $data): Brand
    {
        return DB::transaction(function () use ($brand, $data) {
            if (isset($data['name']) || isset($data['slug'])) {
                $source = filled($data['slug'] ?? null) ? $data['slug'] : ($data['name'] ?? $brand->name);
                $data['slug'] = SlugGenerator::make(Brand::class, $source, $brand->id);
            }

            $brand->update($data);

            return $brand;
        });
    }

    public function delete(Brand $brand): void
    {
        $brand->delete();
    }
}
