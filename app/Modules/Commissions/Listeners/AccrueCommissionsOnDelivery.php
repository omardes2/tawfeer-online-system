<?php

namespace App\Modules\Commissions\Listeners;

use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Sales\Events\OrderDelivered;

/**
 * يستحقّ عمولات الطلب (pending) عند التسليم (Phase 4.2). الاستحقاق النهائي عند التسوية (4.6).
 */
class AccrueCommissionsOnDelivery
{
    public function __construct(private readonly CommissionService $commissions) {}

    public function handle(OrderDelivered $event): void
    {
        $this->commissions->accrueForOrder($event->order);
    }
}
