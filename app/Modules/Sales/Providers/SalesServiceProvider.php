<?php

namespace App\Modules\Sales\Providers;

use App\Modules\Sales\Console\SwapOrderProductCommand;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);

        // الأوامر تُسجَّل صراحةً: لا اكتشاف تلقائيّ لأوامر الوحدات في هذا المشروع،
        // وأمرٌ غير مسجَّل لا يظهر في `artisan list` ولا يُنادى — بلا خطأ يدلّ عليه.
        if ($this->app->runningInConsole()) {
            $this->commands([SwapOrderProductCommand::class]);
        }
    }
}
