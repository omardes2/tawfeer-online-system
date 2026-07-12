<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\OrderPriceChange;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين (Phase 4.1 / ADR-037). نقطة امتداد — يُستهلك محاسبيًا/عموليًا لاحقًا.
 */
class OrderPriceChangeRejected
{
    use Dispatchable;

    public function __construct(public readonly OrderPriceChange $change) {}
}
