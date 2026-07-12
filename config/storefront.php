<?php

use App\Modules\Recommendations\Support\RuleBasedRecommendationProvider;
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
    | مزوّد التوصيات/العروض (جاهزية سياق النمو — ADR-032/034/045). الافتراضي الآن
    | المحرّك القائم على القواعد (Phase 6): related/cross-sell/upsell/fbt/best-sellers
    | من الكتالوج وتاريخ الطلبات مع توافر المخزون وتوصيات يدوية. يمكن الرجوع إلى Null
    | عبر STOREFRONT_RECOMMENDATION_PROVIDER=null دون تعديل المتجر.
    */
    'recommendation_provider' => env('STOREFRONT_RECOMMENDATION_PROVIDER') === 'null'
        ? NullRecommendationProvider::class
        : RuleBasedRecommendationProvider::class,

    /*
    | جاهزية العروض الترويجية (بلا منطق نمو). عتبة الشحن المجاني: null = بلا رسالة.
    | عند ضبط قيمة تُعرض رسالة «أنفق X أكثر». المصدر النهائي: محرّك العروض مستقبلًا.
    */
    'promotions' => [
        'free_shipping_threshold' => env('STOREFRONT_FREE_SHIPPING_THRESHOLD'),
    ],
];
