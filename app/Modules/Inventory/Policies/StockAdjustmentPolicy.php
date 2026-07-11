<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\StockAdjustment;

class StockAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.adjustments.view');
    }

    public function view(User $user, StockAdjustment $a): bool
    {
        return $user->can('inventory.adjustments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.adjustments.create');
    }

    public function update(User $user, StockAdjustment $a): bool
    {
        return $user->can('inventory.adjustments.update');
    }

    public function approve(User $user, StockAdjustment $a): bool
    {
        return $user->can('inventory.adjustments.approve');
    }

    public function post(User $user, StockAdjustment $a): bool
    {
        return $user->can('inventory.adjustments.post');
    }

    public function delete(User $user, StockAdjustment $a): bool
    {
        return $user->can('inventory.adjustments.delete');
    }
}
