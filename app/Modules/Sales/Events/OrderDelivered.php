<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين (ADR-037): سُلّم الطلب. يستهلكه استحقاق العمولة (pending) — Phase 4.2.
 */
class OrderDelivered
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
