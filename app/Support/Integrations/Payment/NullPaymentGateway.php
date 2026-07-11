<?php

namespace App\Support\Integrations\Payment;

use App\Support\Contracts\PaymentGateway;

/**
 * Driver افتراضي "لا مزوّد" — placeholder آمن حتى ربط بوابة حقيقية لاحقًا.
 *
 * يوضّح المبدأ 13: التنفيذ خلف العقد، ويُبدَّل دون لمس منطق الأعمال.
 */
class NullPaymentGateway implements PaymentGateway
{
    public function charge(int $amountMinor, string $currency, array $payload = []): array
    {
        return [
            'status' => 'pending',
            'reference' => 'null_'.uniqid(),
            'driver' => $this->name(),
        ];
    }

    public function verify(string $reference): bool
    {
        return false;
    }

    public function refund(string $reference, ?int $amountMinor = null): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }
}
