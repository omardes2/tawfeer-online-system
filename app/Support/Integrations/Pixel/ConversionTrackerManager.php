<?php

namespace App\Support\Integrations\Pixel;

use App\Support\Contracts\Pixel\ConversionTrackerInterface;
use InvalidArgumentException;

/**
 * المدخل الوحيد إلى محرّكات قياس التحويل — لا يُستدعى محرّك مباشرةً.
 */
class ConversionTrackerManager
{
    public function tracker(?string $driver = null): ConversionTrackerInterface
    {
        $driver ??= (string) config('ads.pixel.driver', 'null');
        $class = config('ads.pixel.drivers.'.$driver);

        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException("محرّك قياس غير معروف: {$driver}");
        }

        return app($class);
    }
}
