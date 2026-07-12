<?php

namespace App\Modules\Returns\Events;

use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين (ADR-040): أُنشئ طلب مرتجع. نقطة امتداد (إشعارات) — لا منطق نمو/تسويق.
 */
class ReturnRequested
{
    use Dispatchable;

    public function __construct(public readonly ReturnRequest $return) {}
}
