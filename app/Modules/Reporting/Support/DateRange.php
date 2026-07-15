<?php

namespace App\Modules\Reporting\Support;

use Carbon\CarbonImmutable;

/**
 * نطاق تاريخ للتقارير (Phase 4.7 / ADR-042) — للقراءة فقط.
 *
 * يدعم اختصارات موحّدة عبر كل التقارير: اليوم/الأمس/آخر 7/آخر 30/هذا الشهر/الشهر
 * الماضي/هذا العام/مخصّص — بالإضافة إلى الاختصارات القديمة (day/week/month) للتوافق.
 */
final class DateRange
{
    public const PRESETS = [
        'today', 'yesterday', 'last_7_days', 'last_30_days',
        'this_month', 'last_month', 'this_year', 'custom',
        // اختصارات قديمة (توافق رجعي مع تقارير Phase 4.7 الأصلية).
        'day', 'week', 'month',
    ];

    /** تسميات عربية للاختصارات (لأزرار الفلتر). */
    public const LABELS = [
        'today' => 'اليوم',
        'yesterday' => 'الأمس',
        'last_7_days' => 'آخر 7 أيام',
        'last_30_days' => 'آخر 30 يومًا',
        'this_month' => 'هذا الشهر',
        'last_month' => 'الشهر الماضي',
        'this_year' => 'هذا العام',
        'custom' => 'مخصّص',
    ];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $preset,
    ) {}

    /** بناء النطاق من طلب (preset + from/to). يعود إلى «هذا الشهر» افتراضيًا. */
    public static function resolve(?string $preset, ?string $from = null, ?string $to = null): self
    {
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'this_month';
        $now = CarbonImmutable::now();

        return match ($preset) {
            'today', 'day' => new self($now->startOfDay(), $now->endOfDay(), 'today'),
            'yesterday' => new self($now->subDay()->startOfDay(), $now->subDay()->endOfDay(), 'yesterday'),
            'last_7_days', 'week' => new self($now->subDays(6)->startOfDay(), $now->endOfDay(), 'last_7_days'),
            'last_30_days' => new self($now->subDays(29)->startOfDay(), $now->endOfDay(), 'last_30_days'),
            'last_month' => new self($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(), 'last_month'),
            'this_year' => new self($now->startOfYear(), $now->endOfYear(), 'this_year'),
            'custom' => new self(
                $from ? CarbonImmutable::parse($from)->startOfDay() : $now->startOfMonth(),
                $to ? CarbonImmutable::parse($to)->endOfDay() : $now->endOfDay(),
                'custom',
            ),
            default => new self($now->startOfMonth(), $now->endOfMonth(), 'this_month'),
        };
    }

    public function fromString(): string
    {
        return $this->from->toDateString();
    }

    public function toString(): string
    {
        return $this->to->toDateString();
    }

    public function label(): string
    {
        return self::LABELS[$this->preset] ?? self::LABELS['this_month'];
    }

    /** @return array{0: string, 1: string} */
    public function bounds(): array
    {
        return [$this->from->toDateTimeString(), $this->to->toDateTimeString()];
    }

    /** مفتاح تخزين مؤقّت مستقرّ للنطاق. */
    public function cacheKey(): string
    {
        return $this->from->format('YmdHis').'-'.$this->to->format('YmdHis');
    }
}
