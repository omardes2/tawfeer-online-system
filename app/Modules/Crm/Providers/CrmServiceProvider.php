<?php

namespace App\Modules\Crm\Providers;

use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Policies\CustomerPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}
