<?php

use App\Support\Integrations\AdPlatform\FakeAdPlatformProvider;
use App\Support\Integrations\AdPlatform\MetaAdsProvider;
use App\Support\Integrations\AdPlatform\NullAdPlatformProvider;
use App\Support\Integrations\Pixel\FakeConversionTracker;
use App\Support\Integrations\Pixel\MetaConversionsApiTracker;
use App\Support\Integrations\Pixel\NullConversionTracker;

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

    /*
    | **قياس التحويل** — بكسل ميتا وConversions API (ADR-054).
    |
    | شرطُ هدف «الشراء عبر الموقع»: المنصّة لا تُحسِّن على ما لا تراه، وبلا حدث
    | شراءٍ يصلها تختار جمهورًا ينقر لا جمهورًا يشتري. والافتراض `null` فالمتجر
    | يعمل كاملًا بلا قياس، والأسرار في `.env` وحدها.
    |
    | ومستقلّ عن حسابَي القراءة والكتابة: البكسل يخصّ **الموقع** لا الحساب
    | الإعلاني، ويُشارَك بين الحسابات — فربطُه بأحدهما كان سيوقف القياس عند أول
    | فصلٍ بينهما.
    */
    'pixel' => [
        'driver' => env('ADS_PIXEL_DRIVER', 'null'),

        'drivers' => [
            'null' => NullConversionTracker::class,
            'fake' => FakeConversionTracker::class,
            'meta' => MetaConversionsApiTracker::class,
        ],

        'id' => env('META_PIXEL_ID'),
        'token' => env('META_PIXEL_TOKEN'),
        // يُملأ أثناء الضبط ليظهر الحدث في أداة فحص الأحداث، ثم يُفرَّغ.
        'test_event_code' => env('META_PIXEL_TEST_EVENT_CODE'),
        'version' => env('META_ADS_VERSION', 'v21.0'),
        'timeout' => 15,
        // مفتاح الدولة للأرقام المحلّية قبل التجزئة — «0599…» ⇐ «970599…».
        'country_code' => env('META_PIXEL_COUNTRY_CODE', '970'),
        // عملة قيمة الحدث — عملة المتجر لا عملة الحساب الإعلاني.
        'currency' => env('META_PIXEL_CURRENCY', 'ILS'),
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
