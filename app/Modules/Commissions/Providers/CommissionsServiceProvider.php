<?php

namespace App\Modules\Commissions\Providers;

use App\Modules\Commissions\Console\AuditEarnerPricesCommand;
use App\Modules\Commissions\Console\RepairWholesaleSnapshotsCommand;
use App\Modules\Commissions\Console\RepriceEarnerCommand;
use App\Modules\Commissions\Listeners\AccrueCommissionsOnDelivery;
use App\Modules\Sales\Events\OrderDelivered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * وحدة العمولات/الأرباح (Phase 4.2 / ADR-037). تربط استحقاق العمولة بحدث التسليم.
 */
class CommissionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderDelivered::class, AccrueCommissionsOnDelivery::class);

        // غير مجدول عمدًا: أمرٌ يحرّك مستحقّات أشخاص يُشغَّل بيدٍ ويُقرأ ناتجه
        // قبل اعتماده، لا يعمل وحده في الليل.
        if ($this->app->runningInConsole()) {
            $this->commands([
                // الفحص يقرأ ولا يكتب — يُشغَّل قبل الإصلاح ليُعرف حجمُه.
                AuditEarnerPricesCommand::class,
                RepairWholesaleSnapshotsCommand::class,
                RepriceEarnerCommand::class,
            ]);
        }
    }
}
