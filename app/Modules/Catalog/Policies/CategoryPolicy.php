<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Category;

/**
 * صلاحيات Category عبر RBAC (المبدأ 12، ADR-021).
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.categories.view');
    }

    public function view(User $user, Category $model): bool
    {
        return $user->can('catalog.categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.categories.create');
    }

    public function update(User $user, Category $model): bool
    {
        return $user->can('catalog.categories.update');
    }

    public function delete(User $user, Category $model): bool
    {
        return $user->can('catalog.categories.delete');
    }
}
