<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformProviderInterface;
use InvalidArgumentException;

/**
 * المدخل الوحيد إلى محرّكات منصّات الإعلان — لا يُستدعى محرّك مباشرةً.
 *
 * وللقراءة وحدها: لا يملك النظام محرّك كتابةٍ إلى المنصّة، فلا سبيل إلى إيقاف
 * إعلانٍ أو تعديل ميزانيةٍ من هنا.
 */
class AdPlatformManager
{
    public function provider(?string $driver = null): AdPlatformProviderInterface
    {
        $driver ??= (string) config('ads.driver', 'null');
        $class = config('ads.drivers.'.$driver);

        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException("محرّك إعلانات غير معروف: {$driver}");
        }

        return app($class);
    }
}
