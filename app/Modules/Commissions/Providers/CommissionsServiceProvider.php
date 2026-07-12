<?php

namespace App\Modules\Commissions\Providers;

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
    }
}
