<?php

use App\Support\Integrations\Messaging\FakeMessagingProvider;
use App\Support\Integrations\Messaging\NullMessagingProvider;
use App\Support\Integrations\Messaging\WhatsAppCloudProvider;

return [
    /*
    | مزوّدو المراسلة (طبقة التكامل — المبدأ 13، ADR-030). جاهز للربط المستقبلي.
    | كل قناة تشير إلى Driver؛ إضافة WhatsApp/Email/SMS/Marketing = Driver ينفّذ
    | MessagingProviderInterface + إدخاله هنا، دون لمس منطق CRM. الأسرار في .env.
    */
    'default' => env('MESSAGING_DRIVER', 'null'),

    'channels' => [
        'whatsapp' => env('MESSAGING_WHATSAPP', 'null'),
        'email' => env('MESSAGING_EMAIL', 'null'),
        'sms' => env('MESSAGING_SMS', 'null'),
        'marketing' => env('MESSAGING_MARKETING', 'null'),
    ],

    /*
    | مفتاح الدولة للأرقام المحلّية عند التوحيد — «0599…» ⇐ «970599…».
    | التوحيد شرطُ عدم التكرار: الرقم نفسه بثلاث صيغٍ يعني ثلاث رسائل لشخصٍ
    | واحد، وهو أسرع طريقٍ إلى الحجب ثم حظر الرقم.
    */
    'country_code' => env('MESSAGING_COUNTRY_CODE', '970'),

    'drivers' => [
        'null' => NullMessagingProvider::class,
        'fake' => FakeMessagingProvider::class, // للاختبارات فقط (Phase 6)
        'whatsapp_cloud' => WhatsAppCloudProvider::class,
        // 'ses'            => \App\Support\Integrations\Messaging\SesEmailProvider::class,       // لاحقًا
    ],

    /*
    | WhatsApp Cloud API (ADR-058). الأسرار في `.env` وحدها.
    |
    | ولا يعمل المحرّك بلا رمزٍ ومعرّف رقم — فيمرّ الإرسال بحالة `skipped` بدل
    | أن يفشل، ويبقى النظام كاملًا بلا ربط.
    */
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 20),
        'default_language' => env('WHATSAPP_DEFAULT_LANGUAGE', 'ar'),

        /*
        | رمز التحقّق الذي تضعه في إعداد الـwebhook لدى ميتا، وسرُّ التطبيق
        | لتوقيع الطلبات. **بلا السرّ لا يُقبل أي webhook** — نقطةٌ عامّة تقبل
        | حالاتٍ بلا تحقّق يستطيع أيُّ أحدٍ أن يُخبرها أن رسائلك سُلّمت.
        */
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

    /*
    | حدود الإرسال المجمّع. الحدّ اليومي هو **طبقة رقمك لدى المنصّة** لا رغبتك:
    | الرقم الجديد يبدأ عند 250 أو 1000 مستلمٍ في 24 ساعة ويرتفع مع الجودة.
    | وتجاوزُه يُرفَض من المنصّة، وتكرار الرفض يُسقط درجة الجودة.
    */
    'bulk' => [
        'daily_limit' => (int) env('WHATSAPP_DAILY_LIMIT', 250),
        // حجم الدفعة الواحدة في تشغيلة الأمر.
        'batch' => (int) env('WHATSAPP_BATCH', 50),
        /*
        | نسبة الفشل التي يتوقّف عندها الإرسال تلقائيًّا (%). الفشل المتراكم
        | مؤشّرُ قائمةٍ رديئة أو قالبٍ مرفوض، والاستمرار عليه يُحرق الرقم.
        */
        'abort_failure_pct' => (int) env('WHATSAPP_ABORT_FAILURE_PCT', 20),
        // أقلّ عدد محاولاتٍ قبل أن تُصدَّق النسبة — ثلاثة إخفاقاتٍ من ثلاثة ليست دليلًا.
        'abort_min_sample' => (int) env('WHATSAPP_ABORT_MIN_SAMPLE', 20),
    ],
];
