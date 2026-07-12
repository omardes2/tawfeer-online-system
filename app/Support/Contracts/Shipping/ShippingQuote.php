<?php

namespace App\Support\Contracts\Shipping;

/**
 * عرض سعر شحن من مزوّد (DTO).
 */
class ShippingQuote
{
    public function __construct(
        public readonly float $cost,
        public readonly string $currency,
        public readonly string $provider,
        public readonly array $meta = [],
    ) {}
}
