<?php

namespace App\Console\Commands;

use App\Modules\Shipping\Services\DeliverySyncService;
use Illuminate\Console\Command;

/**
 * مزامنة حالات التوصيل للشحنات النشطة (Phase 4.3 / ADR-039).
 */
class SyncDeliveryStatusesCommand extends Command
{
    protected $signature = 'delivery:sync';

    protected $description = 'مزامنة حالات التوصيل للشحنات النشطة عبر مزوّديها';

    public function handle(DeliverySyncService $service): int
    {
        $counts = $service->syncActive();
        $this->info(sprintf(
            'delivery sync: synced=%d skipped=%d failed=%d inconsistent=%d',
            $counts['synced'], $counts['skipped'], $counts['failed'], $counts['inconsistent']
        ));

        return self::SUCCESS;
    }
}
