<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * محرّك وهمي للاختبارات — تُزرَع فيه الصفوف ولا يخرج منه نداء شبكي واحد.
 *
 * كل اختبارات المزامنة تمرّ من هنا: اختبارٌ يعتمد على Meta الحقيقية ليس اختبارًا
 * بل رهانٌ على شبكةٍ وحسابٍ خارجيين.
 */
class FakeAdPlatformProvider implements AdPlatformProviderInterface
{
    /** @var array<int, AdInsightRow> */
    private static array $rows = [];

    private static bool $configured = true;

    /** @param  array<int, AdInsightRow>  $rows */
    public static function fake(array $rows, bool $configured = true): void
    {
        self::$rows = $rows;
        self::$configured = $configured;
    }

    public static function reset(): void
    {
        self::$rows = [];
        self::$configured = true;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function dailyInsights(Carbon $from, Carbon $to): Collection
    {
        return collect(self::$rows)->filter(
            fn (AdInsightRow $row) => $row->date >= $from->toDateString() && $row->date <= $to->toDateString(),
        )->values();
    }
}
