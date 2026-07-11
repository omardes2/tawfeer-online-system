<?php

namespace App\Modules\Sales\Providers;

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
    }
}
