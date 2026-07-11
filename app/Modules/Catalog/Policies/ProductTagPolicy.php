<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\ProductTag;

/**
 * صلاحيات ProductTag عبر RBAC (المبدأ 12، ADR-021).
 */
class ProductTagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.tags.view');
    }

    public function view(User $user, ProductTag $model): bool
    {
        return $user->can('catalog.tags.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.tags.create');
    }

    public function update(User $user, ProductTag $model): bool
    {
        return $user->can('catalog.tags.update');
    }

    public function delete(User $user, ProductTag $model): bool
    {
        return $user->can('catalog.tags.delete');
    }
}
