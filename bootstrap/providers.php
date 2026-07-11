<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Foundation\Providers\FoundationServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Purchasing\Providers\PurchasingServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    FoundationServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    PurchasingServiceProvider::class,
    SalesServiceProvider::class,
];
