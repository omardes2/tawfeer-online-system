<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\ProductAttribute;

/**
 * صلاحيات ProductAttribute عبر RBAC (المبدأ 12، ADR-021).
 */
class ProductAttributePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.attributes.view');
    }

    public function view(User $user, ProductAttribute $model): bool
    {
        return $user->can('catalog.attributes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.attributes.create');
    }

    public function update(User $user, ProductAttribute $model): bool
    {
        return $user->can('catalog.attributes.update');
    }

    public function delete(User $user, ProductAttribute $model): bool
    {
        return $user->can('catalog.attributes.delete');
    }
}
