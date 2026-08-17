<?php

use App\Support\Integrations\AdPlatform\FakeAdPlatformProvider;
use App\Support\Integrations\AdPlatform\MetaAdsProvider;
use App\Support\Integrations\AdPlatform\NullAdPlatformProvider;

return [
    /*
    | منصّة الإعلانات (المبدأ 12 — عقد ومحرّكات). الافتراضي `null` فالنظام يعمل
    | كاملًا بلا ربط: الصرف يُدخَل يدويًّا كما كان. الأسرار في `.env` وحدها.
    */
    'driver' => env('ADS_DRIVER', 'null'),

    'drivers' => [
        'null' => NullAdPlatformProvider::class,
        'fake' => FakeAdPlatformProvider::class, // للاختبارات — بلا أي اتصال شبكي.
        'meta' => MetaAdsProvider::class,
    ],

    'meta' => [
        'token' => env('META_ADS_TOKEN'),
        // معرّف الحساب الإعلاني كما في رابط مدير الإعلانات بعد `act=` (بلا البادئة).
        'account_id' => env('META_ADS_ACCOUNT_ID'),
        // إصدار الـAPI في الإعداد لا في الكود: ترقيته لا يجوز أن تحتاج نشرًا.
        'version' => env('META_ADS_API_VERSION', 'v21.0'),

        /*
        | نوع الحدث الذي يُعدّ «محادثة» — هدفُ حملاتك «الرسائل»، فالنتيجة محادثة
        | لا طلب. في الإعداد لأن Meta تُغيّر تسمياتها، ولأن حسابًا بهدفٍ آخر
        | (مبيعات مثلًا) يحتاج نوعًا مختلفًا بلا لمس الكود.
        */
        'conversation_action' => env('META_ADS_CONVERSATION_ACTION', 'onsite_conversion.messaging_conversation_started_7d'),

        'timeout' => (int) env('META_ADS_TIMEOUT', 30),
    ],

    'sync' => [
        'enabled' => (bool) env('ADS_SYNC_ENABLED', false),
        'cron' => env('ADS_SYNC_CRON', '30 4 * * *'),

        /*
        | تُعاد مزامنة آخر N يومًا لا يومِ أمس وحده: أرقام Meta تُراجَع خلال 24–72
        | ساعة، فالرقم الأوّلي ليس نهائيًّا. بدون هذا يتجمّد صرفٌ ناقص ويُبنى عليه
        | حكمُ إيقافٍ أو زيادة.
        */
        'lookback_days' => (int) env('ADS_SYNC_LOOKBACK_DAYS', 3),
    ],
];
