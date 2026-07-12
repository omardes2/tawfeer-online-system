<?php

namespace App\Support\Storefront;

use App\Modules\Catalog\Models\Product;
use App\Support\Contracts\StorefrontRecommendationProvider;
use Illuminate\Support\Collection;

/**
 * المزوّد الافتراضي (ADR-034): يخدم ما هو حقيقة كتالوجية فقط —
 * «مميّز» (is_featured) و«وصل حديثًا» (الأحدث) — ويُعيد بقيّة أقسام النمو
 * (best-sellers/related/cross-sell/upsell/bundles/personalized) **فارغة**.
 * لا منطق نمو هنا؛ الأقسام الفارغة نقاط امتداد جاهزة (ADR-032).
 */
class NullRecommendationProvider implements StorefrontRecommendationProvider
{
    /** @return Collection<int, Product> */
    public function featured(int $limit = 8): Collection
    {
        return Product::query()->active()->visible()
            ->where('is_featured', true)
            ->with(['primaryImage', 'defaultVariant', 'brand'])
            ->latest('id')->limit($limit)->get();
    }

    /** @return Collection<int, Product> */
    public function newArrivals(int $limit = 8): Collection
    {
        return Product::query()->active()->visible()
            ->with(['primaryImage', 'defaultVariant', 'brand'])
            ->latest('id')->limit($limit)->get();
    }

    /** @return Collection<int, Product> */
    public function bestSellers(int $limit = 8): Collection
    {
        return collect(); // مؤجّل — يتطلّب تجميع مبيعات (محرّك النمو/التحليلات).
    }

    /** @return Collection<int, Product> */
    public function related(Product $product, int $limit = 8): Collection
    {
        return collect(); // مؤجّل — منطق توصية (محرّك النمو).
    }

    /** @return Collection<int, Product> */
    public function frequentlyBoughtTogether(Product $product, int $limit = 8): Collection
    {
        return collect();
    }

    /** @return Collection<int, Product> */
    public function crossSell(Product $product, int $limit = 8): Collection
    {
        return collect();
    }

    /** @return Collection<int, Product> */
    public function upsell(Product $product, int $limit = 8): Collection
    {
        return collect();
    }

    /** @return Collection<int, Product> */
    public function bundles(Product $product, int $limit = 8): Collection
    {
        return collect();
    }

    /** @return Collection<int, Product> */
    public function personalized(?int $userId, int $limit = 8): Collection
    {
        return collect();
    }
}
