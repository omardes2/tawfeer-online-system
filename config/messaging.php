<?php

use App\Support\Integrations\Messaging\FakeMessagingProvider;
use App\Support\Integrations\Messaging\NullMessagingProvider;

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

    'drivers' => [
        'null' => NullMessagingProvider::class,
        'fake' => FakeMessagingProvider::class, // للاختبارات فقط (Phase 6)
        // 'whatsapp_cloud' => \App\Support\Integrations\Messaging\WhatsAppCloudProvider::class, // لاحقًا
        // 'ses'            => \App\Support\Integrations\Messaging\SesEmailProvider::class,       // لاحقًا
    ],
];
