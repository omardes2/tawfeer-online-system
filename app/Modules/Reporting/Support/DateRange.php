<?php

namespace App\Modules\Reporting\Support;

use Carbon\CarbonImmutable;

/**
 * نطاق تاريخ للتقارير (Phase 4.7 / ADR-042) — يومي/أسبوعي/شهري/مخصّص. للقراءة فقط.
 */
final class DateRange
{
    public const PRESETS = ['day', 'week', 'month', 'custom'];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $preset,
    ) {}

    /** بناء النطاق من طلب (preset + from/to). يعود إلى الشهر الحالي افتراضيًا. */
    public static function resolve(?string $preset, ?string $from = null, ?string $to = null): self
    {
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'month';
        $now = CarbonImmutable::now();

        return match ($preset) {
            'day' => new self($now->startOfDay(), $now->endOfDay(), 'day'),
            'week' => new self($now->startOfWeek(), $now->endOfWeek(), 'week'),
            'custom' => new self(
                $from ? CarbonImmutable::parse($from)->startOfDay() : $now->startOfMonth(),
                $to ? CarbonImmutable::parse($to)->endOfDay() : $now->endOfDay(),
                'custom',
            ),
            default => new self($now->startOfMonth(), $now->endOfMonth(), 'month'),
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

    /** @return array{0: string, 1: string} */
    public function bounds(): array
    {
        return [$this->from->toDateTimeString(), $this->to->toDateTimeString()];
    }
}
