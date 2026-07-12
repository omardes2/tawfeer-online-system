<?php

namespace App\Modules\Shipping\Providers;

use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Policies\ShipmentPolicy;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use App\Support\Contracts\Shipping\GeographySyncProviderInterface;
use App\Support\Contracts\Shipping\ShippingQuoteProviderInterface;
use App\Support\Integrations\Shipping\NullDeliveryProvider;
use App\Support\Integrations\Shipping\NullGeographySyncProvider;
use App\Support\Integrations\Shipping\NullShippingQuoteProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * ربط عقود التكامل بالـDrivers المختارة من config/shipping.php (المبدأ 13، ADR-027).
 * الوصول للمزوّد حصريًا عبر هذه العقود المحقونة — لا استدعاء مباشر من متحكم/نموذج.
 */
class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $drivers = config('shipping.drivers.'.config('shipping.provider', 'null'), []);

        $this->app->bind(DeliveryProviderInterface::class, $drivers['delivery'] ?? NullDeliveryProvider::class);
        $this->app->bind(ShippingQuoteProviderInterface::class, $drivers['quote'] ?? NullShippingQuoteProvider::class);
        $this->app->bind(GeographySyncProviderInterface::class, $drivers['geography_sync'] ?? NullGeographySyncProvider::class);
    }

    public function boot(): void
    {
        Gate::policy(Shipment::class, ShipmentPolicy::class);
    }
}
