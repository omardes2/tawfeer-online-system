<?php

namespace App\Modules\Ai\Providers;

use App\Support\Contracts\Ai\AiContentProviderInterface;
use Illuminate\Support\ServiceProvider;

/**
 * وحدة الذكاء الاصطناعي (Ai — Phase 6 / ADR-044، المبدأ 13).
 * تربط عقد مزوّد المحتوى بالمُعدّ في config/ai.php (Null افتراضيًا). إضافة مزوّد
 * (OpenAI/غيره) = صنف + إدخال في الإعداد، دون لمس المتحكّمات أو منطق الأعمال.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiContentProviderInterface::class, function ($app) {
            $default = config('ai.default', 'null');
            $class = config('ai.drivers.'.$default, config('ai.drivers.null'));

            return $app->make($class);
        });
    }
}
