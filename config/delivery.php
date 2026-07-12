<?php

/*
| إعدادات عمليات التوصيل (Phase 4.3 / ADR-038، ADR-039).
| مستقلّة عن المزوّد؛ منطق المزوّد يبقى في الـDriver. الأسرار في .env.
*/
return [
    // المزامنة المجدولة (استطلاع حالة الشحنات النشطة).
    'sync' => [
        'enabled' => env('DELIVERY_SYNC_ENABLED', false),
        'cron' => env('DELIVERY_SYNC_CRON', '*/15 * * * *'),
        'chunk' => 100,
        'max_attempts' => 5, // إعادة محاولة المزامنة الفاشلة.
    ],

    // الـwebhook لكل مزوّد (سرّ التوقيع). التحقّق يقع في الـDriver إن دعمه.
    'webhook' => [
        'secrets' => [
            'opost' => env('OPOST_WEBHOOK_SECRET'),
        ],
    ],

    // تصعيد الاستثناءات المجدول.
    'escalation' => [
        'enabled' => env('DELIVERY_ESCALATION_ENABLED', false),
        'cron' => env('DELIVERY_ESCALATION_CRON', '0 * * * *'),
    ],

    // محرّك الرسوم — مكوّنات مستقلّة عن المزوّد وقابلة للتوسّع.
    'fees' => [
        'types' => [
            'delivery',     // رسوم التوصيل الأساسية
            'cod',          // رسوم الدفع عند الاستلام
            'oversize',     // شحنة كبيرة الحجم
            'overweight',   // وزن زائد
            'remote_area',  // منطقة نائية
            'return',       // رسوم إرجاع
            'exchange',     // رسوم استبدال
            'retry',        // إعادة محاولة توصيل
            'manual',       // رسم يدوي
            'discount',     // خصم (سالب)
        ],
        'owners' => ['business', 'customer', 'provider'],
        'sources' => ['quoted', 'charged', 'actual', 'manual'],
    ],
];
