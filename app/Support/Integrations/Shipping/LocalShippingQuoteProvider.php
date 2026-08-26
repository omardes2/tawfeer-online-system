<?php

namespace App\Support\Integrations\Shipping;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Support\Contracts\Shipping\ShippingQuote;
use App\Support\Contracts\Shipping\ShippingQuoteProviderInterface;
use App\Support\Contracts\Shipping\ShippingQuoteRequest;

/**
 * مزوّد تسعير محلّي (المبدأ 13، ADR-027): يقرأ سعر التوصيل من جدول أسعار المدن
 * (نمط Opost) — تُزامَن المدن من المزوّد وتُدار الأسعار في لوحة التحكّم. يُرجع null
 * إن لم يوجد سعر مضبوط للمدينة (لا يفرض تكلفة خاطئة).
 */
class LocalShippingQuoteProvider implements ShippingQuoteProviderInterface
{
    public function quote(ShippingQuoteRequest $request): ?ShippingQuote
    {
        $cityId = $request->cityId;

        // إن لم تُمرَّر مدينة لكن مُرِّرت منطقة، استنتج المدينة من المنطقة.
        if ($cityId === null && $request->areaId !== null) {
            $cityId = Area::whereKey($request->areaId)->value('city_id');
        }

        if ($cityId === null) {
            return null;
        }

        $rate = DeliveryCityRate::query()
            ->where('is_active', true)
            ->where('city_id', $cityId)
            ->first();

        if ($rate === null) {
            return null;
        }

        // رقمان لا رقم: التكلفة لدى المزوّد، وسعرُ البيع للزبون. ومن لم يُضبط
        // له سعرُ بيعٍ يبقى الاثنان متساويين تمامًا كما كان.
        return new ShippingQuote(
            cost: $rate->providerCost(),
            currency: $rate->currency ?: 'ILS',
            provider: 'local',
            customerFee: $rate->customerFee(),
        );
    }

    public function name(): string
    {
        return 'local';
    }
}
