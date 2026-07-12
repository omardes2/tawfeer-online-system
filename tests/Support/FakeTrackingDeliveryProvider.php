<?php

namespace Tests\Support;

use App\Support\Integrations\Shipping\OpostDeliveryProvider;

/**
 * Driver اختبار: يرث تعيين حالات Opost ويعيد حالة تتبّع قابلة للضبط (لمحاكاة المزامنة).
 */
class FakeTrackingDeliveryProvider extends OpostDeliveryProvider
{
    /** الحالة الخام التي يعيدها track() (تضبطها الاختبارات). */
    public static ?string $trackStatus = null;

    /** محاكاة فشل التتبّع (لاختبار إعادة المحاولة). */
    public static bool $throw = false;

    public function track(string $trackingNumber): array
    {
        if (self::$throw) {
            throw new \RuntimeException('provider unavailable');
        }

        return ['provider_status' => self::$trackStatus, 'external_id' => $trackingNumber, 'driver' => $this->name()];
    }

    public function name(): string
    {
        return 'faketrack';
    }
}
