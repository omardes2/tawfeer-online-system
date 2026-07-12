<?php

namespace App\Support\Integrations\Shipping;

use App\Support\Contracts\Shipping\GeographySyncProviderInterface;

/**
 * Driver افتراضي "لا مزوّد" لمزامنة الجغرافيا — placeholder آمن (لا شبكة). لا يُرجع سجلّات.
 */
class NullGeographySyncProvider implements GeographySyncProviderInterface
{
    public function pullGovernorates(): iterable
    {
        return [];
    }

    public function pullCities(string $governorateExternalId): iterable
    {
        return [];
    }

    public function pullAreas(string $cityExternalId): iterable
    {
        return [];
    }

    public function name(): string
    {
        return 'null';
    }
}
