<?php

namespace App\Modules\Store\Providers;

use App\Support\Contracts\SocialAuthProvider;
use App\Support\Contracts\StorefrontRecommendationProvider;
use App\Support\Social\SocialiteAuthProvider;
use Illuminate\Auth\Notifications\ResetPassword;
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

        // المصادقة الاجتماعية عبر Socialite (يُبدَّل بمزوّد وهمي في الاختبارات — ADR-036).
        $this->app->bind(SocialAuthProvider::class, SocialiteAuthProvider::class);
    }

    public function boot(): void
    {
        // روابط استرجاع كلمة المرور تشير إلى واجهة المتجر (Phase 3.5). الرمز صالح لأي مسار.
        ResetPassword::createUrlUsing(
            fn ($user, string $token) => route('account.password.reset', ['token' => $token]).'?email='.urlencode($user->getEmailForPasswordReset()),
        );
    }
}
