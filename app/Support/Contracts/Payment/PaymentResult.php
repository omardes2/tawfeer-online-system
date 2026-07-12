<?php

namespace App\Support\Contracts\Payment;

/**
 * نتيجة عملية دفع من مزوّد (DTO).
 */
class PaymentResult
{
    public function __construct(
        public readonly string $status,        // pending/processing/paid/failed/cancelled/refunded
        public readonly ?string $reference = null,
        public readonly ?string $redirectUrl = null,
        public readonly array $raw = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
