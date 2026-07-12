<?php

namespace App\Support\Integrations\Payment;

use App\Support\Contracts\Payment\PaymentChargeRequest;
use App\Support\Contracts\Payment\PaymentProviderInterface;
use App\Support\Contracts\Payment\PaymentResult;

/**
 * Driver الدفع الأوفلاين (COD / تحويل بنكي) — لا اتصال شبكي؛ التحصيل يدوي/عند الاستلام.
 * charge يُبقيه pending؛ capture يسجّل التحصيل (paid)؛ refund يسجّل استردادًا يدويًا.
 */
class OfflinePaymentProvider implements PaymentProviderInterface
{
    public function charge(PaymentChargeRequest $request): PaymentResult
    {
        // لا تحصيل فوري — بانتظار الاستلام/التحويل.
        return new PaymentResult('pending', raw: ['driver' => $this->name(), 'method' => $request->methodCode]);
    }

    public function capture(string $reference, ?int $amountMinor = null): PaymentResult
    {
        // تسجيل التحصيل اليدوي (مثل استلام النقد عند التسليم).
        return new PaymentResult('paid', reference: $reference ?: null, raw: ['driver' => $this->name()]);
    }

    public function verify(string $reference): PaymentResult
    {
        return new PaymentResult('pending', reference: $reference, raw: ['driver' => $this->name()]);
    }

    public function refund(string $reference, ?int $amountMinor = null): PaymentResult
    {
        return new PaymentResult('refunded', reference: $reference ?: null, raw: ['driver' => $this->name()]);
    }

    public function name(): string
    {
        return 'offline';
    }
}
