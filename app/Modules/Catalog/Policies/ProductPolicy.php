<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Product;

/**
 * صلاحيات المنتجات عبر RBAC (المبدأ 12، ADR-021).
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('catalog.products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('catalog.products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('catalog.products.delete');
    }
}
