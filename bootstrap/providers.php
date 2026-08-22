<?php

use App\Modules\Accounting\Providers\AccountingServiceProvider;
use App\Modules\Ai\Providers\AiServiceProvider;
use App\Modules\AiAgent\Providers\AiAgentServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Commissions\Providers\CommissionsServiceProvider;
use App\Modules\Crm\Providers\CrmServiceProvider;
use App\Modules\Foundation\Providers\FoundationServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Marketing\Providers\MarketingServiceProvider;
use App\Modules\Payment\Providers\PaymentServiceProvider;
use App\Modules\Purchasing\Providers\PurchasingServiceProvider;
use App\Modules\Recommendations\Providers\RecommendationsServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Modules\Shipping\Providers\ShippingServiceProvider;
use App\Modules\Store\Providers\StoreServiceProvider;
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
    AccountingServiceProvider::class,
    CrmServiceProvider::class,
    StoreServiceProvider::class,
    CommissionsServiceProvider::class,
    // Phase 6 — الذكاء الاصطناعي والتوصيات والتسويق
    AiServiceProvider::class,
    AiAgentServiceProvider::class,
    RecommendationsServiceProvider::class,
    MarketingServiceProvider::class,
];
