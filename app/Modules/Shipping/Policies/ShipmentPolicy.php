<?php

namespace App\Modules\Shipping\Policies;

use App\Models\User;
use App\Modules\Shipping\Models\Shipment;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('shipping.shipments.view');
    }

    public function view(User $user, Shipment $m): bool
    {
        return $user->can('shipping.shipments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('shipping.shipments.create');
    }

    public function update(User $user, Shipment $m): bool
    {
        return $user->can('shipping.shipments.update');
    }

    public function dispatchShipment(User $user, Shipment $m): bool
    {
        return $user->can('shipping.shipments.dispatch');
    }

    public function deliver(User $user, Shipment $m): bool
    {
        return $user->can('shipping.shipments.deliver');
    }

    public function fail(User $user, Shipment $m): bool
    {
        return $user->can('shipping.shipments.fail');
    }

    public function overrideCost(User $user, Shipment $m): bool
    {
        return $user->can('shipping.override_cost');
    }
}
