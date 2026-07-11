<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * إدارة وسائط المنتج: رفع إلى قرص public، وضمان صورة أساسية واحدة.
 */
class ProductImageService
{
    public function store(Product $product, UploadedFile $file, array $meta = []): ProductImage
    {
        return DB::transaction(function () use ($product, $file, $meta) {
            $path = $file->store('products', 'public');

            // أول صورة للمنتج تصبح أساسية تلقائيًا.
            $makePrimary = ($meta['is_primary'] ?? false) || ! $product->images()->exists();

            $image = $product->images()->create([
                'path' => $path,
                'alt' => $meta['alt'] ?? null,
                'sort_order' => $meta['sort_order'] ?? 0,
                'is_primary' => $makePrimary,
            ]);

            if ($makePrimary) {
                $this->promote($product, $image);
            }

            return $image;
        });
    }

    public function setPrimary(Product $product, ProductImage $image): ProductImage
    {
        DB::transaction(function () use ($product, $image) {
            $image->update(['is_primary' => true]);
            $this->promote($product, $image);
        });

        return $image->refresh();
    }

    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            $product = $image->product;
            $wasPrimary = $image->is_primary;

            Storage::disk('public')->delete($image->path);
            $image->delete();

            // إن كانت الأساسية، رقّي أقدم صورة متبقّية.
            if ($wasPrimary && $next = $product->images()->orderBy('sort_order')->first()) {
                $next->update(['is_primary' => true]);
            }
        });
    }

    /**
     * تصفير الأساسية عن بقية صور المنتج.
     */
    private function promote(Product $product, ProductImage $image): void
    {
        $product->images()->whereKeyNot($image->id)->where('is_primary', true)->update(['is_primary' => false]);
    }
}
