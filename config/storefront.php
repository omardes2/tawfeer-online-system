<?php

use App\Support\Storefront\NullRecommendationProvider;

return [
    /*
    | واجهة المتجر (Storefront — Phase 3.3). إعدادات العرض ونقاط الامتداد.
    */

    // عدد المنتجات في الصفحة (Pagination).
    'per_page' => 12,

    // اللغة الافتراضية للمتجر (عربي أساسي — RTL).
    'default_locale' => 'ar',
    'locales' => ['ar', 'en'],

    /*
    | مزوّد التوصيات/العروض (جاهزية سياق النمو — ADR-032/034). الافتراضي Null:
    | featured/new-arrivals من الكتالوج؛ best-sellers/related/cross-sell/upsell/
    | bundles/personalized تُعاد فارغة حتى يُربط محرّك النمو (بلا تعديل المتجر).
    */
    'recommendation_provider' => NullRecommendationProvider::class,

    /*
    | جاهزية العروض الترويجية (بلا منطق نمو). عتبة الشحن المجاني: null = بلا رسالة.
    | عند ضبط قيمة تُعرض رسالة «أنفق X أكثر». المصدر النهائي: محرّك العروض مستقبلًا.
    */
    'promotions' => [
        'free_shipping_threshold' => env('STOREFRONT_FREE_SHIPPING_THRESHOLD'),
    ],
];
