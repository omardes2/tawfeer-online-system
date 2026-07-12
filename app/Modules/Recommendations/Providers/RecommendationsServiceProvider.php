<?php

namespace App\Modules\Recommendations\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * وحدة التوصيات (Recommendations — Phase 6 / ADR-045).
 *
 * لا تحتاج ربطًا صريحًا: المتجر يربط StorefrontRecommendationProvider من
 * config/storefront.php (RuleBasedRecommendationProvider افتراضيًا)، وRecommendationService
 * يُحلّ تلقائيًا. النقطة محجوزة لأي إعداد/مستمعات مستقبلية للوحدة.
 */
class RecommendationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
