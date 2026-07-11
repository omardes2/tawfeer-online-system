<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Brand;

/**
 * صلاحيات Brand عبر RBAC (المبدأ 12، ADR-021).
 */
class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.brands.view');
    }

    public function view(User $user, Brand $model): bool
    {
        return $user->can('catalog.brands.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.brands.create');
    }

    public function update(User $user, Brand $model): bool
    {
        return $user->can('catalog.brands.update');
    }

    public function delete(User $user, Brand $model): bool
    {
        return $user->can('catalog.brands.delete');
    }
}
