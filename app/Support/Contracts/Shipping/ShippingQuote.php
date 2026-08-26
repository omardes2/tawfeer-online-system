<?php

namespace App\Support\Contracts\Shipping;

/**
 * عرض سعر شحن من مزوّد (DTO).
 *
 * `cost` ما ندفعه للمزوّد، و`customerFee` ما نتقاضاه من الزبون. وهما مختلفان
 * حين يُضبط للمدينة سعرُ بيع؛ ومتساويان حين لا يُضبط — فالفراغ يعني «بلا هامش».
 */
class ShippingQuote
{
    public function __construct(
        public readonly float $cost,
        public readonly string $currency,
        public readonly string $provider,
        public readonly array $meta = [],
        public readonly ?float $customerFee = null,
    ) {}

    /** ما يُتقاضى من الزبون — سعرُ البيع إن وُجد، وإلّا التكلفة نفسها. */
    public function customerFee(): float
    {
        return round($this->customerFee ?? $this->cost, 2);
    }
}
