<?php

use App\Support\Integrations\AdPlatform\FakeAdPlatformProvider;
use App\Support\Integrations\AdPlatform\FakeAdPlatformWriter;
use App\Support\Integrations\AdPlatform\MetaAdsProvider;
use App\Support\Integrations\AdPlatform\MetaAdsWriter;
use App\Support\Integrations\AdPlatform\NullAdPlatformProvider;
use App\Support\Integrations\AdPlatform\NullAdPlatformWriter;

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
    | **الكتابة** — منفصلة عن القراءة بمحرّكٍ ورمزٍ وإعدادٍ مستقلّ (ADR-053).
    |
    | الافتراض `null`: النظام لا يملك صلاحية إنفاق مالٍ إلّا بقرارٍ صريح على
    | الخادم. ورمز الكتابة غيرُ رمز القراءة — صلاحيته `ads_management`، وغيابُه
    | يُبقي محرّك الكتابة «غير مضبوط» ولو كان رمز القراءة حاضرًا.
    */
    'write' => [
        'driver' => env('ADS_WRITE_DRIVER', 'null'),

        'drivers' => [
            'null' => NullAdPlatformWriter::class,
            'fake' => FakeAdPlatformWriter::class, // للاختبارات — بلا أي اتصال شبكي.
            'meta' => MetaAdsWriter::class,
        ],

        'token' => env('META_ADS_WRITE_TOKEN'),

        /*
        | معامل الوحدة الصغرى لكل عملة. Meta تقبل الميزانية بالسنت لا بالدولار،
        | ومعظم العملات على 100 — والاستثناءات هنا لأن خلط الوحدتين يصرف مئة
        | ضعفٍ بلا أن ترفضه المنصّة.
        */
        'currency_offsets' => [
            'JPY' => 1, 'KRW' => 1, 'VND' => 1, 'CLP' => 1, 'ISK' => 1, 'PYG' => 1, 'UGX' => 1,
        ],
    ],

    /*
    | الطيّار الآلي (ADR-053). الجدولة هنا، أمّا التفعيل والسقف والصفحات فمن
    | لوحة التحكّم (المبدأ 8) — هي أرقام عملٍ يغيّرها صاحب العمل بلا نشر.
    */
    'autopilot' => [
        // بعد المزامنة بساعة: القرار على صرفٍ لم يُسحَب بعدُ قرارٌ على فراغ.
        'cron' => env('ADS_AUTOPILOT_CRON', '30 5 * * *'),
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
