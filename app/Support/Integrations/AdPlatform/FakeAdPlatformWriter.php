<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformWriterInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * محرّك كتابة وهمي للاختبارات — يحفظ الحالة في الذاكرة بلا نداء شبكي.
 *
 * ويسجّل كل نداء في `$calls`: أهمّ ما تفحصه اختبارات الطيّار ليس ما كُتب في
 * جدول القرارات، بل **ما وصل المنصّة فعلًا** — فقرارٌ سُجّل ولم يُرسَل، أو
 * أُرسل مرّتين، لا يظهر إلّا هنا.
 */
class FakeAdPlatformWriter implements AdPlatformWriterInterface
{
    /** @var array<string, AdSetState> */
    private static array $sets = [];

    /** @var array<int, array{0: string, 1: string, 2: float|null}> */
    private static array $calls = [];

    private static bool $configured = true;

    /** @var array<string, string> معرّفات يفشل الكتابةُ إليها — لاختبار الفشل الجزئي. */
    private static array $failing = [];

    /** @param  array<int, AdSetState>  $sets */
    public static function fake(array $sets, bool $configured = true): void
    {
        self::$sets = collect($sets)->keyBy('id')->all();
        self::$configured = $configured;
        self::$calls = [];
        self::$failing = [];
    }

    public static function failOn(string $adSetId, string $message = 'رفضت المنصّة الطلب.'): void
    {
        self::$failing[$adSetId] = $message;
    }

    public static function reset(): void
    {
        self::$sets = [];
        self::$calls = [];
        self::$failing = [];
        self::$configured = true;
    }

    /** @return array<int, array{0: string, 1: string, 2: float|null}> */
    public static function calls(): array
    {
        return self::$calls;
    }

    public static function state(string $adSetId): ?AdSetState
    {
        return self::$sets[$adSetId] ?? null;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function adSets(array $externalIds): Collection
    {
        return collect(self::$sets)->only($externalIds);
    }

    public function pause(string $adSetId): void
    {
        $this->record('pause', $adSetId);
        $this->replace($adSetId, status: 'PAUSED');
    }

    public function resume(string $adSetId): void
    {
        $this->record('resume', $adSetId);
        $this->replace($adSetId, status: 'ACTIVE');
    }

    public function setDailyBudget(string $adSetId, float $amount): void
    {
        $this->record('budget', $adSetId, $amount);
        $this->replace($adSetId, budget: $amount);
    }

    private function record(string $action, string $adSetId, ?float $amount = null): void
    {
        if (isset(self::$failing[$adSetId])) {
            throw new RuntimeException(self::$failing[$adSetId]);
        }

        self::$calls[] = [$action, $adSetId, $amount];
    }

    private function replace(string $adSetId, ?string $status = null, ?float $budget = null): void
    {
        $old = self::$sets[$adSetId] ?? null;

        if (! $old) {
            return;
        }

        self::$sets[$adSetId] = new AdSetState(
            id: $old->id,
            name: $old->name,
            status: $status ?? $old->status,
            effectiveStatus: $status ?? $old->effectiveStatus,
            dailyBudget: $budget ?? $old->dailyBudget,
            lifetimeBudget: $old->lifetimeBudget,
            campaignId: $old->campaignId,
            currency: $old->currency,
        );
    }
}
