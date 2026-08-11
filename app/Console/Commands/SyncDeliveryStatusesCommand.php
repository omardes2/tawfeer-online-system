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
            'delivery sync: synced=%d unchanged=%d failed=%d inconsistent=%d not-dispatched=%d',
            $counts['synced'], $counts['skipped'], $counts['failed'], $counts['inconsistent'], $counts['no_reference'] ?? 0
        ));

        // شرح بالعربية لأن «skipped» وحدها كانت تُقرأ خطأً كأنها مشكلة أو كأنها نجاح.
        $this->line(sprintf(
            '  ✔ حُدّثت: %d   ▪ بلا تغيير لدى المزوّد: %d   ✖ فشل استعلام: %d   ⚠ تحتاج مراجعة: %d   ▫ لم تُرسَل بعد: %d',
            $counts['synced'], $counts['skipped'], $counts['failed'], $counts['inconsistent'], $counts['no_reference'] ?? 0
        ));

        if ($counts['failed'] > 0) {
            $this->warn('  راجع «الشحن والتوصيل ← أحداث شركة التوصيل» لمعرفة سبب الفشل.');
        }

        return self::SUCCESS;
    }
}
