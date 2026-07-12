<?php

namespace App\Support\Integrations\Payment;

use App\Support\Contracts\Payment\PaymentChargeRequest;
use App\Support\Contracts\Payment\PaymentProviderInterface;
use App\Support\Contracts\Payment\PaymentResult;

/**
 * Driver افتراضي "لا مزوّد" لبوابات الأونلاين غير المُهيّأة — placeholder آمن (لا شبكة).
 * يُبقي الدفع pending حتى ربط بوابة حقيقية (HyperPay/Stripe/... لاحقًا).
 */
class NullPaymentProvider implements PaymentProviderInterface
{
    public function charge(PaymentChargeRequest $request): PaymentResult
    {
        return new PaymentResult('pending', reference: 'null_'.uniqid(), raw: ['driver' => $this->name()]);
    }

    public function capture(string $reference, ?int $amountMinor = null): PaymentResult
    {
        return new PaymentResult('pending', reference: $reference, raw: ['driver' => $this->name()]);
    }

    public function verify(string $reference): PaymentResult
    {
        return new PaymentResult('pending', reference: $reference, raw: ['driver' => $this->name()]);
    }

    public function refund(string $reference, ?int $amountMinor = null): PaymentResult
    {
        return new PaymentResult('failed', reference: $reference, raw: ['driver' => $this->name()]);
    }

    public function name(): string
    {
        return 'null';
    }
}
