<?php

use App\Support\Integrations\Ai\NullAiContentProvider;
use App\Support\Integrations\Ai\OpenAiContentProvider;

/*
| مزوّد الذكاء الاصطناعي لمحتوى المنتجات (Phase 6 / ADR-044، المبدأ 13).
| مجرّد خلف عقد + Driver؛ إضافة مزوّد = صنف + إدخال هنا، دون لمس منطق الأعمال.
| الأسرار في .env (config/services.php:openai) — لا مفاتيح هنا.
*/
return [
    'default' => env('AI_PROVIDER', 'null'),

    'drivers' => [
        'null' => NullAiContentProvider::class,
        'openai' => OpenAiContentProvider::class,
    ],

    'model' => env('AI_MODEL', 'gpt-4o-mini'),
    'timeout' => (int) env('AI_TIMEOUT', 30),
    'max_retries' => (int) env('AI_MAX_RETRIES', 2),

    // حدّ معدّل الاستخدام لكل مستخدم (طلبات/دقيقة).
    'rate_limit' => (int) env('AI_RATE_LIMIT', 20),

    // إرسال صورة المنتج المحدّدة عند دعم التحليل البصري (تُمرّر بإذن صريح فقط).
    'vision' => (bool) env('AI_VISION', false),
];
