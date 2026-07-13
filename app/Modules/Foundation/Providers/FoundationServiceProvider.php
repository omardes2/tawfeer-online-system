<?php

namespace App\Modules\Foundation\Providers;

use App\Modules\Foundation\Services\SettingsManager;
use App\Modules\Foundation\Services\SystemSettingsService;
use App\Support\Contracts\PaymentGateway;
use App\Support\Integrations\Payment\NullPaymentGateway;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
        $this->applyDynamicSettings();
    }

    /**
     * جسر الإعدادات الديناميكية → config وقت التشغيل (المبدأ 9).
     * **الأسرار من .env لها الأولوية**؛ إعدادات قاعدة البيانات تملأ الفراغ فقط.
     * مُحصَّن: يُتجاهَل بأمان قبل توفّر جدول الإعدادات (وقت الترحيل/الاختبارات الفارغة).
     */
    private function applyDynamicSettings(): void
    {
        try {
            /** @var SettingsManager $settings */
            $settings = $this->app->make(SettingsManager::class);
            $all = $settings->all();
            if ($all === []) {
                return;
            }

            /** @var SystemSettingsService $sys */
            $sys = $this->app->make(SystemSettingsService::class);

            // الذكاء الاصطناعي — env أولًا، ثم الإعدادات.
            if (empty(config('services.openai.key')) && ($key = $sys->secret('openai.key'))) {
                Config::set('services.openai.key', $key);
            }
            if (! empty($all['openai.model'] ?? null)) {
                Config::set('ai.model', $all['openai.model']);
            }
            if (env('AI_PROVIDER') === null && ($all['openai.enabled'] ?? false)) {
                Config::set('ai.default', 'openai');
            }

            // البريد — إعدادات SMTP من قاعدة البيانات (كلمة المرور من env أولًا).
            if (! empty($all['mail.host'] ?? null)) {
                Config::set('mail.default', 'smtp');
                Config::set('mail.mailers.smtp.host', $all['mail.host']);
                Config::set('mail.mailers.smtp.port', (int) ($all['mail.port'] ?? 587));
                Config::set('mail.mailers.smtp.username', $all['mail.username'] ?? null);
                Config::set('mail.mailers.smtp.encryption', ($all['mail.encryption'] ?? 'tls') === 'null' ? null : ($all['mail.encryption'] ?? 'tls'));
                if (empty(env('MAIL_PASSWORD')) && ($pw = $sys->secret('mail.password'))) {
                    Config::set('mail.mailers.smtp.password', $pw);
                }
                if (! empty($all['mail.from_address'] ?? null)) {
                    Config::set('mail.from.address', $all['mail.from_address']);
                }
                if (! empty($all['mail.from_name'] ?? null)) {
                    Config::set('mail.from.name', $all['mail.from_name']);
                }
            }

            // النظام — المنطقة الزمنية واللغة الافتراضية.
            if (! empty($all['system.timezone'] ?? null)) {
                Config::set('app.timezone', $all['system.timezone']);
                date_default_timezone_set($all['system.timezone']);
            }
            if (! empty($all['system.locale'] ?? null)) {
                Config::set('app.locale', $all['system.locale']);
            }
        } catch (Throwable) {
            // جدول الإعدادات غير متاح بعد (ترحيل) — تجاهل بأمان.
        }
    }
}
