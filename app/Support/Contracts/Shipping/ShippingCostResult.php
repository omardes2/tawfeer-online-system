<?php

namespace App\Support\Contracts\Shipping;

/**
 * نتيجة حلّ تكلفة الشحن (DTO) — تُلقَّط على الشحنة والطلب (المتطلّب 8).
 *
 * `cost` تُكتب على **الشحنة** (ما ندفع لشركة التوصيل)، و`customerFee` تُكتب
 * على **الطلب** (ما يدفعه الزبون). كانا رقمًا واحدًا يُكتب في المكانين، فكان
 * الهامش صفرًا بحكم البنية لا بحكم التسعير.
 */
class ShippingCostResult
{
    public function __construct(
        public readonly float $cost,
        public readonly string $source, // provider_live/provider_synced/zone/manual/pending
        public readonly ?string $currency = null,
        public readonly ?float $customerFee = null,
    ) {}

    /** ما يُتقاضى من الزبون — سعرُ البيع إن وُجد، وإلّا التكلفة نفسها. */
    public function customerFee(): float
    {
        return round($this->customerFee ?? $this->cost, 2);
    }

    /** هامش الطرد: ما يُتقاضى ناقص ما يُدفَع. */
    public function margin(): float
    {
        return round($this->customerFee() - $this->cost, 2);
    }
}
