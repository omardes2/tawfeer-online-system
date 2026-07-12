<?php

namespace App\Support\Contracts\Shipping;

/**
 * طلب عرض سعر شحن (DTO) — يُمرَّر لطبقة التكامل بمعرّفات مُعيَّنة لا أسماء (المتطلّب 9).
 */
class ShippingQuoteRequest
{
    public function __construct(
        public readonly ?int $governorateId = null,
        public readonly ?int $cityId = null,
        public readonly ?int $areaId = null,
        public readonly ?int $shippingZoneId = null,
        public readonly ?string $providerExternalId = null,
        public readonly float $weight = 0.0,
        public readonly array $meta = [],
    ) {}
}
