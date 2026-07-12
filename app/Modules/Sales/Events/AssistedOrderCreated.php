<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين (Phase 4.1 / ADR-037). نقطة امتداد — يُستهلك محاسبيًا/عموليًا لاحقًا.
 */
class AssistedOrderCreated
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
