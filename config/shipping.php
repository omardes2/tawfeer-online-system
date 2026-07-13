<?php

use App\Support\Integrations\Shipping\LocalShippingQuoteProvider;
use App\Support\Integrations\Shipping\NullDeliveryProvider;
use App\Support\Integrations\Shipping\NullGeographySyncProvider;
use App\Support\Integrations\Shipping\NullShippingQuoteProvider;
use App\Support\Integrations\Shipping\OpostDeliveryProvider;
use App\Support\Integrations\Shipping\OpostGeographySyncProvider;

return [
    /*
    | مزوّد التوصيل الافتراضي (طبقة التكامل — المبدأ 13، ADR-027).
    | تبديل المزوّد = إضافة Driver + تغيير هذا الإعداد، دون لمس منطق الأعمال.
    | الأسرار في .env؛ هنا فقط مفتاح الاختيار.
    */
    'provider' => env('SHIPPING_PROVIDER', 'null'),

    'drivers' => [
        'null' => [
            'delivery' => NullDeliveryProvider::class,
            'quote' => NullShippingQuoteProvider::class,
            'geography_sync' => NullGeographySyncProvider::class,
        ],
        // Opost: يوفّر تعيين الحالات القانونية (ADR-038)؛ الـAPI الحيّ يُربط عبر .env لاحقًا.
        'opost' => [
            'delivery' => OpostDeliveryProvider::class,
            // التسعير محلّي (أسعار المدن تُدار في لوحة التحكّم) — يقرأ delivery_city_rates.
            'quote' => LocalShippingQuoteProvider::class,
            'geography_sync' => OpostGeographySyncProvider::class,
        ],
    ],
];
