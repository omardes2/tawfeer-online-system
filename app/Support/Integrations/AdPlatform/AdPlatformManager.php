<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformProviderInterface;
use App\Support\Contracts\AdPlatform\AdPlatformWriterInterface;
use InvalidArgumentException;

/**
 * المدخل الوحيد إلى محرّكات منصّات الإعلان — لا يُستدعى محرّك مباشرةً.
 *
 * القراءة والكتابة محرّكان مستقلّان بإعدادين مستقلّين: تفعيلُ القراءة لا يفعّل
 * الكتابة، ولا سبيل إلى الصرف عبر `provider()` مهما أُسيء استعماله.
 */
class AdPlatformManager
{
    public function provider(?string $driver = null): AdPlatformProviderInterface
    {
        $driver ??= (string) config('ads.driver', 'null');

        return $this->resolve('ads.drivers.'.$driver, $driver);
    }

    /** محرّك الكتابة — افتراضُه `null` دائمًا، فالصرف لا يُفتح سهوًا. */
    public function writer(?string $driver = null): AdPlatformWriterInterface
    {
        $driver ??= (string) config('ads.write.driver', 'null');

        return $this->resolve('ads.write.drivers.'.$driver, $driver);
    }

    private function resolve(string $configKey, string $driver): mixed
    {
        $class = config($configKey);

        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException("محرّك إعلانات غير معروف: {$driver}");
        }

        return app($class);
    }
}
