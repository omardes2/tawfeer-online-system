<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Order;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.orders.view');
    }

    public function view(User $user, Order $m): bool
    {
        return $user->can('sales.orders.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.orders.create');
    }

    public function update(User $user, Order $m): bool
    {
        return $user->can('sales.orders.update');
    }

    public function delete(User $user, Order $m): bool
    {
        return $user->can('sales.orders.delete');
    }

    public function confirm(User $user, Order $m): bool
    {
        return $user->can('sales.orders.confirm');
    }

    public function reserve(User $user, Order $m): bool
    {
        return $user->can('sales.orders.reserve');
    }

    public function ship(User $user, Order $m): bool
    {
        return $user->can('sales.orders.ship');
    }

    public function deliver(User $user, Order $m): bool
    {
        return $user->can('sales.orders.deliver');
    }

    public function cancel(User $user, Order $m): bool
    {
        return $user->can('sales.orders.cancel');
    }
}
