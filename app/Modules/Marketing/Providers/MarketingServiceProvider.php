<?php

namespace App\Modules\Marketing\Providers;

use App\Modules\Marketing\Console\RunAbandonedCartsCommand;
use App\Modules\Marketing\Console\RunAbandonedCheckoutsCommand;
use App\Modules\Marketing\Console\RunBirthdayCampaignsCommand;
use App\Modules\Marketing\Listeners\MarketingEventSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * وحدة أتمتة التسويق (Marketing — Phase 6 / ADR-046).
 *
 * تسجّل مستمع التحفيز بالأحداث (يعتمد على أحداث الدومين القائمة) وأوامر الجدولة.
 * كل الإرسال عبر MessagingManager (مستقلّ عن المزوّد)؛ لا أسرار هنا.
 */
class MarketingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::subscribe(MarketingEventSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunBirthdayCampaignsCommand::class,
                RunAbandonedCartsCommand::class,
                RunAbandonedCheckoutsCommand::class,
            ]);
        }
    }
}
