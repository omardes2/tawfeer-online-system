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

    /*
    | أقصى عدد طرود تقبله شركة التوصيل في الشحنة الواحدة. تجاوزه يُرفض لديها
    | برمز 422 — ورفضٌ لا تنفع معه إعادة المحاولة. يُفحص قبل الإرسال بدل انتظار
    | الرفض. القيمة من المزوّد (Opost: 12)، وتُغيَّر من .env إن رُفع السقف.
    */
    'max_parcels_per_shipment' => (int) env('SHIPPING_MAX_PARCELS', 12),

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
