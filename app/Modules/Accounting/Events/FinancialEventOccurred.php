<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث مالي عام (ADR-016/029، BR-ACC-01/11). تطلقه وحدات الأعمال دون معرفة بالمحاسبة؛
 * يستهلكه مستمع المحاسبة ليولّد قيدًا. عزل المحاسبة عن الوحدات (المتطلّب 6).
 */
class FinancialEventOccurred
{
    use Dispatchable;

    /**
     * @param  string  $type  نوع الحدث (مثل cash_collection) — مُعرّف في config/accounting.php
     * @param  array<string, mixed>  $context  amount/date/reference_type/reference_id/description
     */
    public function __construct(
        public readonly string $type,
        public readonly array $context = [],
    ) {}
}
