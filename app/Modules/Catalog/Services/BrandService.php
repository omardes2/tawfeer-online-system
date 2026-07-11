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
            $data['slug'] = SlugGenerator::make(Brand::class, $data['slug'] ?? $data['name']);

            return Brand::create($data);
        });
    }

    public function update(Brand $brand, array $data): Brand
    {
        return DB::transaction(function () use ($brand, $data) {
            if (isset($data['name']) || isset($data['slug'])) {
                $data['slug'] = SlugGenerator::make(Brand::class, $data['slug'] ?? $data['name'] ?? $brand->name, $brand->id);
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
