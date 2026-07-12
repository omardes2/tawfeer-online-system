<?php

namespace App\Modules\Recommendations\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Recommendations\Services\RecommendationService;
use App\Support\Contracts\StorefrontRecommendationProvider;
use Illuminate\Support\Collection;

/**
 * مزوّد توصيات قائم على القواعد (Phase 6 / ADR-045) — ينفّذ عقد المتجر ذاته
 * (StorefrontRecommendationProvider) فيحلّ محلّ Null دون تعديل المتجر (تبديل عبر
 * config/storefront.php). مجرّد رفيع فوق RecommendationService (منطق القواعد + التوافر
 * + التوصيات اليدوية). أي مزوّد ذكاء اصطناعي مستقبلي ينفّذ العقد نفسه.
 */
class RuleBasedRecommendationProvider implements StorefrontRecommendationProvider
{
    public function __construct(private readonly RecommendationService $service) {}

    public function featured(int $limit = 8): Collection
    {
        return $this->service->featured($limit);
    }

    public function bestSellers(int $limit = 8): Collection
    {
        return $this->service->bestSellers($limit);
    }

    public function newArrivals(int $limit = 8): Collection
    {
        return $this->service->newArrivals($limit);
    }

    public function related(Product $product, int $limit = 8): Collection
    {
        return $this->service->related($product, $limit);
    }

    public function frequentlyBoughtTogether(Product $product, int $limit = 8): Collection
    {
        return $this->service->frequentlyBoughtTogether($product, $limit);
    }

    public function crossSell(Product $product, int $limit = 8): Collection
    {
        return $this->service->crossSell($product, $limit);
    }

    public function upsell(Product $product, int $limit = 8): Collection
    {
        return $this->service->upsell($product, $limit);
    }

    public function bundles(Product $product, int $limit = 8): Collection
    {
        return $this->service->bundles($product, $limit);
    }

    public function personalized(?int $userId, int $limit = 8): Collection
    {
        return $this->service->personalized($userId, $limit);
    }
}
