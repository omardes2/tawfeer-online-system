<?php

namespace App\Modules\Shipping\Services;

use App\Support\Contracts\Shipping\ShippingCostResult;
use App\Support\Contracts\Shipping\ShippingQuoteProviderInterface;
use App\Support\Contracts\Shipping\ShippingQuoteRequest;

/**
 * مُحلّل تكلفة الشحن (ADR-027، المتطلّب 6/7/8). أولوية صارمة مع احتياط:
 *   1) عرض حيّ من المزوّد  2) أحدث سعر مُزامَن  3) سعر المنطقة المحلي
 *   4) تجاوز يدوي (بصلاحية)  5) مراجعة يدوية (pending).
 *
 * المستويات 2/3 مُعرّفة معماريًا ومؤجّلة تنفيذيًا (لا محرّك تسعير — Phase 3).
 */
class ShippingCostResolver
{
    public function __construct(private readonly ShippingQuoteProviderInterface $quoteProvider) {}

    /**
     * @param  array{governorate_id?:int, city_id?:int, area_id?:int, shipping_zone_id?:int, provider_external_id?:string, weight?:float}  $context
     */
    public function resolve(array $context, ?float $manualOverride = null): ShippingCostResult
    {
        // (1) عرض حيّ من المزوّد.
        $quote = $this->quoteProvider->quote(new ShippingQuoteRequest(
            governorateId: $context['governorate_id'] ?? null,
            cityId: $context['city_id'] ?? null,
            areaId: $context['area_id'] ?? null,
            shippingZoneId: $context['shipping_zone_id'] ?? null,
            providerExternalId: $context['provider_external_id'] ?? null,
            weight: (float) ($context['weight'] ?? 0),
        ));
        if ($quote !== null) {
            return new ShippingCostResult($quote->cost, 'provider_live', $quote->currency);
        }

        // (2) أحدث سعر مُزامَن، (3) سعر المنطقة المحلي — مؤجّلان (Phase 3): لا محرّك تسعير الآن.

        // (4) تجاوز يدوي (تُفحص صلاحيته في الطبقة الأعلى).
        if ($manualOverride !== null) {
            return new ShippingCostResult($manualOverride, 'manual');
        }

        // (5) مراجعة يدوية.
        return new ShippingCostResult(0.0, 'pending');
    }
}
