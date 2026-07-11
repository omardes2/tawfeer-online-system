<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Unit;

/**
 * صلاحيات Unit عبر RBAC (المبدأ 12، ADR-021).
 */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.units.view');
    }

    public function view(User $user, Unit $model): bool
    {
        return $user->can('catalog.units.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.units.create');
    }

    public function update(User $user, Unit $model): bool
    {
        return $user->can('catalog.units.update');
    }

    public function delete(User $user, Unit $model): bool
    {
        return $user->can('catalog.units.delete');
    }
}
