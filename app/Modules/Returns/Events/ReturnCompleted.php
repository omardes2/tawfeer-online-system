<?php

namespace App\Modules\Returns\Events;

use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين (ADR-040): اكتمل طلب المرتجع (عكس مخزون/إيراد/عمولة + تسوية مالية).
 * نقطة امتداد للترحيل المحاسبي العكسي (4.6/Phase 5).
 */
class ReturnCompleted
{
    use Dispatchable;

    public function __construct(public readonly ReturnRequest $return) {}
}
