<?php

namespace App\Console\Commands;

use App\Modules\Shipping\Services\DeliveryExceptionService;
use Illuminate\Console\Command;

/**
 * تصعيد استثناءات التوصيل المتجاوزة مهلة التصعيد (Phase 4.3 / ADR-039).
 */
class EscalateDeliveryExceptionsCommand extends Command
{
    protected $signature = 'delivery:escalate-exceptions';

    protected $description = 'تصعيد استثناءات التوصيل المتجاوزة مهلة التصعيد وفق فئاتها';

    public function handle(DeliveryExceptionService $service): int
    {
        $count = $service->escalateOverdue();
        $this->info("escalated {$count} delivery exception(s)");

        return self::SUCCESS;
    }
}
