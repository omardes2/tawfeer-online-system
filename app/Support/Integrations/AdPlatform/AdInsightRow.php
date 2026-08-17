<?php

namespace App\Support\Integrations\AdPlatform;

/**
 * صفٌّ واحد من نتائج المنصّة: مجموعة إعلانية في يوم.
 *
 * `adsetId`/`campaignId` نصّية لا أعداد: معرّفات Meta تتجاوز حدود العدد الصحيح
 * في بعض البيئات، وهي مفاتيح لا تُحسَب بها عملية.
 */
final readonly class AdInsightRow
{
    public function __construct(
        public string $date,
        public string $campaignId,
        public string $campaignName,
        public string $adsetId,
        public string $adsetName,
        public float $spend,
        public int $conversations,
        public string $currency = 'USD',
    ) {}
}
