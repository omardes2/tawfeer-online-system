<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Policies\StockAdjustmentPolicy;
use App\Modules\Inventory\Policies\StockReservationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * مزوّد خدمة وحدة المخزون (المبدأ 14). يسجّل سياسات الصلاحيات صراحةً.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class);
        Gate::policy(StockReservation::class, StockReservationPolicy::class);
    }
}
