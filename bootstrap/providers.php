<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Foundation\Providers\FoundationServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Payment\Providers\PaymentServiceProvider;
use App\Modules\Purchasing\Providers\PurchasingServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Modules\Shipping\Providers\ShippingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    FoundationServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    PurchasingServiceProvider::class,
    SalesServiceProvider::class,
    ShippingServiceProvider::class,
    PaymentServiceProvider::class,
];
