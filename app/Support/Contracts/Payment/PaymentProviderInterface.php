<?php

namespace App\Support\Contracts\Payment;

/**
 * عقد مزوّد الدفع (المبدأ 13، ADR-019/028). أي بوابة/طريقة دفع خلف هذا العقد + Driver.
 * لا يُستدعى مزوّد مباشرةً من متحكم/خدمة أعمال — فقط عبر هذه الطبقة (المتطلّب 6).
 * يدعم مزوّدين مستقبليين: COD, Bank Transfer, HyperPay, MyFatoorah, Stripe, PayPal, Moyasar...
 */
interface PaymentProviderInterface
{
    /** بدء تحصيل — بوابة أونلاين تُرجع مرجعًا/رابطًا؛ طريقة أوفلاين تُرجع pending للتحصيل اليدوي. */
    public function charge(PaymentChargeRequest $request): PaymentResult;

    /** تأكيد/تحصيل دفعة (offline: تسجيل التحصيل اليدوي؛ online: capture لعملية مُصرّح بها). */
    public function capture(string $reference, ?int $amountMinor = null): PaymentResult;

    /** التحقّق من حالة عملية (يُستخدم في callbacks/webhooks). */
    public function verify(string $reference): PaymentResult;

    /** استرداد كامل أو جزئي. */
    public function refund(string $reference, ?int $amountMinor = null): PaymentResult;

    /** اسم/مفتاح المزوّد (للتسجيل). */
    public function name(): string;
}
