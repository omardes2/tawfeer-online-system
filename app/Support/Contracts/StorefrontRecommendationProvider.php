<?php

namespace App\Support\Contracts;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * عقد توصيات/اكتشاف المتجر (ADR-034 / جاهزية سياق النمو ADR-032).
 *
 * تُعيد الوحدات مجموعات منتجات لعرضها في أقسام التسويق. الافتراضي (Null) يخدم
 * المشتقّ من الكتالوج (مميّز/وصل حديثًا) ويُعيد الباقي فارغًا حتى يُربط محرّك النمو.
 * أي مزوّد نمو مستقبلي يُنفّذ هذا العقد دون تعديل المتجر (تبديل عبر الإعداد).
 *
 * @return Collection<int, Product>
 */
interface StorefrontRecommendationProvider
{
    public function featured(int $limit = 8): Collection;

    public function bestSellers(int $limit = 8): Collection;

    public function newArrivals(int $limit = 8): Collection;

    public function related(Product $product, int $limit = 8): Collection;

    public function frequentlyBoughtTogether(Product $product, int $limit = 8): Collection;

    public function crossSell(Product $product, int $limit = 8): Collection;

    public function upsell(Product $product, int $limit = 8): Collection;

    public function bundles(Product $product, int $limit = 8): Collection;

    public function personalized(?int $userId, int $limit = 8): Collection;
}
