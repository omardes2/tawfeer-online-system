<?php

namespace App\Support\Integrations\Shipping;

use App\Support\Contracts\Shipping\ShippingQuote;
use App\Support\Contracts\Shipping\ShippingQuoteProviderInterface;
use App\Support\Contracts\Shipping\ShippingQuoteRequest;

/**
 * Driver افتراضي "لا مزوّد" لعرض التسعير — placeholder آمن (لا شبكة). يعيد null دائمًا.
 */
class NullShippingQuoteProvider implements ShippingQuoteProviderInterface
{
    public function quote(ShippingQuoteRequest $request): ?ShippingQuote
    {
        return null;
    }

    public function name(): string
    {
        return 'null';
    }
}
