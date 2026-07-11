<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Foundation\Providers\FoundationServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    FoundationServiceProvider::class,
    CatalogServiceProvider::class,
];
