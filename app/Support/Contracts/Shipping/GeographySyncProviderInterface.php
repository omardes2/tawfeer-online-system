<?php

namespace App\Support\Contracts\Shipping;

/**
 * عقد مزامنة الجغرافيا من مزوّد (المبدأ 13، ADR-027).
 * المحلي مرجع النظام؛ المزامنة تملأ جدول التعيين (geo_provider_mappings) لا تستبدل المحلي.
 */
interface GeographySyncProviderInterface
{
    /** @return iterable<array<string, mixed>> */
    public function pullGovernorates(): iterable;

    /** @return iterable<array<string, mixed>> */
    public function pullCities(string $governorateExternalId): iterable;

    /** @return iterable<array<string, mixed>> */
    public function pullAreas(string $cityExternalId): iterable;

    public function name(): string;
}
