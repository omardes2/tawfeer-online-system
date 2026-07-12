<?php

namespace App\Support\Contracts\Payment;

/**
 * طلب تحصيل دفع (DTO) — يُمرَّر لطبقة التكامل. المبالغ بالوحدة الصغرى (minor) لتفادي float.
 */
class PaymentChargeRequest
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $orderNumber,
        public readonly string $methodCode,
        public readonly array $meta = [],
    ) {}
}
