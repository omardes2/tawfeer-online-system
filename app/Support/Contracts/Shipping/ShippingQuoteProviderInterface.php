<?php

namespace App\Support\Contracts\Shipping;

/**
 * عقد عرض تسعير الشحن (المبدأ 13، ADR-027). يُرجع null عند عدم توفّر عرض.
 */
interface ShippingQuoteProviderInterface
{
    public function quote(ShippingQuoteRequest $request): ?ShippingQuote;

    public function name(): string;
}
