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

    /** نتيجة createShipment القابلة للضبط (لمحاكاة نجاح/فشل الإرسال لشركة التوصيل). */
    public static ?array $createResult = null;

    public function createShipment(array $payload): array
    {
        return self::$createResult ?? [
            'status' => 'created',
            'tracking_number' => 'FT-'.($payload['reference'] ?? 'X'),
            'external_id' => 'EXT-'.($payload['reference'] ?? 'X'),
            'provider_status' => 'submitted',
            'raw' => [],
        ];
    }

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
