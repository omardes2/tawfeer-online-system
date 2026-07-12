<?php

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * طبقة قراءة المتجر (ADR-034): استعلامات العرض العامّة (منتجات معروضة/فعّالة،
 * فلترة/بحث/ترتيب/ترقيم) وتسعير/توافر يُعاد استخدامهما من `CartService` (لا تكرار
 * منطق أعمال). لا كتابة هنا — قراءة فقط.
 */
class StorefrontService
{
    public function __construct(private readonly CartService $carts) {}

    /**
     * قائمة المنتجات المعروضة مع فلترة/بحث/ترتيب/ترقيم.
     *
     * @param  array<string, mixed>  $filters  category/brand (slug)، q، min، max، sort
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query()->active()->visible()
            ->with(['primaryImage', 'defaultVariant', 'brand', 'category']);

        if (! empty($filters['category'])) {
            $query->whereHas('category', fn (Builder $q) => $q->where('slug', $filters['category']));
        }
        if (! empty($filters['brand'])) {
            $query->whereHas('brand', fn (Builder $q) => $q->where('slug', $filters['brand']));
        }
        if (! empty($filters['q'])) {
            $this->applySearch($query, (string) $filters['q']);
        }
        if (isset($filters['min']) && $filters['min'] !== '') {
            $query->where('retail_price', '>=', (float) $filters['min']);
        }
        if (isset($filters['max']) && $filters['max'] !== '') {
            $query->where('retail_price', '<=', (float) $filters['max']);
        }

        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate((int) config('storefront.per_page', 12))->withQueryString();
    }

    /** بحث نصّي عبر الاسم (عربي/إنجليزي)، SKU، وكلمات البحث. */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.trim($term).'%';
        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('search_keywords', 'like', $like);
        });
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('retail_price'),
            'price_desc' => $query->orderByDesc('retail_price'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('id'), // الأحدث
        };
    }

    /** منتج معروض بالـslug (أو 404). */
    public function findProductBySlug(string $slug): Product
    {
        return Product::query()->active()->visible()
            ->where('slug', $slug)
            ->with(['images', 'variants', 'defaultVariant', 'brand', 'category', 'attributes.values', 'unit'])
            ->firstOrFail();
    }

    /** @return Collection<int, Category> */
    public function categories(): Collection
    {
        return Category::query()->active()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function findCategoryBySlug(string $slug): Category
    {
        return Category::query()->active()->where('slug', $slug)->firstOrFail();
    }

    /** @return Collection<int, Brand> */
    public function brands(): Collection
    {
        return Brand::query()->active()->orderBy('name')->get();
    }

    public function findBrandBySlug(string $slug): Brand
    {
        return Brand::query()->active()->where('slug', $slug)->firstOrFail();
    }

    // ---- تسعير/توافر (يُعاد استخدامهما من CartService — لا تكرار منطق) ----

    public function sellingPrice(Product $product): float
    {
        $variant = $product->defaultVariant;

        return $variant ? $this->carts->sellingPrice($variant) : (float) $product->retail_price;
    }

    public function regularPrice(Product $product): float
    {
        $variant = $product->defaultVariant;

        return (float) ($variant?->retail_price ?? $product->retail_price);
    }

    /** على عرض ترويجي؟ (سعر البيع أقل من التجزئة). */
    public function onSale(Product $product): bool
    {
        return $this->sellingPrice($product) + 1e-9 < $this->regularPrice($product);
    }

    /**
     * التوافر عبر المستودعات (Σ on_hand − reserved). عند تمرير فرع، يُحصر بمستودعاته
     * (توافر مدرك للفرع — where applicable).
     */
    public function availableQty(Product $product, ?Branch $branch = null): float
    {
        $variant = $product->defaultVariant;
        if (! $variant) {
            return 0.0;
        }
        if ($branch === null) {
            return $this->carts->availableQty($variant);
        }

        $stocks = InventoryStock::query()
            ->where('variant_id', $variant->id)
            ->whereHas('warehouse', fn (Builder $q) => $q->where('branch_id', $branch->id))
            ->get();

        return (float) $stocks->sum(fn ($s) => (float) $s->on_hand - (float) $s->reserved);
    }

    public function inStock(Product $product, ?Branch $branch = null): bool
    {
        return $this->availableQty($product, $branch) > 1e-9;
    }
}
