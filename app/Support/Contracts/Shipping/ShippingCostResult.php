<?php

namespace App\Support\Contracts\Shipping;

/**
 * نتيجة حلّ تكلفة الشحن (DTO) — تُلقَّط على الشحنة والطلب (المتطلّب 8).
 */
class ShippingCostResult
{
    public function __construct(
        public readonly float $cost,
        public readonly string $source, // provider_live/provider_synced/zone/manual/pending
        public readonly ?string $currency = null,
    ) {}
}
