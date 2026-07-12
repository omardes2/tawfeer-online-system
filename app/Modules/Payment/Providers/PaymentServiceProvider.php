<?php

namespace App\Modules\Payment\Providers;

use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Policies\PaymentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * تسجيل سياسة المدفوعات. مزوّدو الدفع يُحقَنون عبر PaymentProviderManager (طبقة التكامل، ADR-028).
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Payment::class, PaymentPolicy::class);
    }
}
