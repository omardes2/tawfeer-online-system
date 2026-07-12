<?php

namespace App\Modules\Store\Providers;

use App\Support\Contracts\StorefrontRecommendationProvider;
use Illuminate\Support\ServiceProvider;

/**
 * وحدة المتجر (Store). تربط عقد التوصيات بالمزوّد المُعدّ (Null افتراضيًا — ADR-034)؛
 * التبديل لمحرّك النمو مستقبلًا عبر `config/storefront.php` دون تعديل المتجر.
 */
class StoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            StorefrontRecommendationProvider::class,
            fn () => $this->app->make(config('storefront.recommendation_provider')),
        );
    }
}
