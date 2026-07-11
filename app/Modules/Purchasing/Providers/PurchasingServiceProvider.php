<?php

namespace App\Modules\Purchasing\Providers;

use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Policies\GoodsReceiptPolicy;
use App\Modules\Purchasing\Policies\PurchaseOrderPolicy;
use App\Modules\Purchasing\Policies\SupplierPolicy;
use App\Modules\Purchasing\Policies\SupplierReturnPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(GoodsReceipt::class, GoodsReceiptPolicy::class);
        Gate::policy(SupplierReturn::class, SupplierReturnPolicy::class);
    }
}
