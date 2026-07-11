<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\StockReservation;

class StockReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.reservations.view');
    }

    public function view(User $user, StockReservation $r): bool
    {
        return $user->can('inventory.reservations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.reservations.create');
    }

    public function release(User $user, StockReservation $r): bool
    {
        return $user->can('inventory.reservations.release');
    }
}
