<?php

use App\Support\Integrations\Payment\NullPaymentProvider;
use App\Support\Integrations\Payment\OfflinePaymentProvider;

return [
    /*
    | خريطة Drivers مزوّدي الدفع (طبقة التكامل — المبدأ 13، ADR-028).
    | كل طريقة دفع تشير إلى مفتاح Driver هنا. إضافة بوابة حقيقية = إضافة Driver ينفّذ
    | PaymentProviderInterface + إدخاله هنا + تفعيل طريقتها، دون لمس منطق الطلب/الدفع.
    | الأسرار في .env؛ هنا فقط ربط الأصناف.
    */
    'drivers' => [
        'null' => NullPaymentProvider::class,       // بوابات أونلاين غير مُهيّأة بعد
        'offline' => OfflinePaymentProvider::class, // COD / تحويل بنكي (تحصيل يدوي)
        // 'hyperpay'  => \App\Support\Integrations\Payment\HyperPayProvider::class,   // لاحقًا
        // 'myfatoorah'=> \App\Support\Integrations\Payment\MyFatoorahProvider::class, // لاحقًا
        // 'stripe'    => \App\Support\Integrations\Payment\StripeProvider::class,     // لاحقًا
        // 'paypal'    => \App\Support\Integrations\Payment\PayPalProvider::class,     // لاحقًا
        // 'moyasar'   => \App\Support\Integrations\Payment\MoyasarProvider::class,    // لاحقًا
    ],
];
