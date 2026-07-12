<?php

namespace App\Modules\Commissions\Events;

use App\Modules\Commissions\Models\CommissionEntry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين عمولة (Phase 4.2 / ADR-037). نقطة امتداد لـ4.4 (المرتجعات) و4.6 (المحاسبة).
 */
class CommissionAdjusted
{
    use Dispatchable;

    public function __construct(public readonly CommissionEntry $entry) {}
}
