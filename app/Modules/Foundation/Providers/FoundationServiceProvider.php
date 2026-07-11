<?php

namespace App\Modules\Foundation\Providers;

use App\Modules\Foundation\Services\SettingsManager;
use App\Support\Contracts\PaymentGateway;
use App\Support\Integrations\Payment\NullPaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * مزوّد خدمة وحدة الأساس (Foundation Module).
 *
 * يربط الطبقات المشتركة: الإعدادات الديناميكية، وطبقة التكامل (Contracts → Drivers).
 * كل وحدة مستقبلية تحصل على مزوّدها الخاص (المبدأ 14: وحدات مستقلة).
 */
class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // إعدادات ديناميكية كـ singleton (المبدأ 9) — نفس النسخة خلال الطلب الواحد.
        $this->app->singleton(SettingsManager::class);

        // طبقة التكامل (المبدأ 13): اربط العقد بالـ Driver المختار من الإعدادات.
        // تبديل المزوّد لاحقًا = تغيير هذا الربط أو الإعداد فقط، دون لمس منطق الأعمال.
        $this->app->bind(PaymentGateway::class, function () {
            return match (config('services.payment.driver', 'null')) {
                // 'tap' => new TapPaymentGateway(...),
                default => new NullPaymentGateway,
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
