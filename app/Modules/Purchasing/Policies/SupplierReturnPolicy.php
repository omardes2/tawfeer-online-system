<?php

namespace App\Modules\Purchasing\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\SupplierReturn;

class SupplierReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.returns.view');
    }

    public function view(User $user, SupplierReturn $m): bool
    {
        return $user->can('purchasing.returns.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.returns.create');
    }

    public function update(User $user, SupplierReturn $m): bool
    {
        return $user->can('purchasing.returns.update');
    }

    public function delete(User $user, SupplierReturn $m): bool
    {
        return $user->can('purchasing.returns.delete');
    }

    public function approve(User $user, SupplierReturn $m): bool
    {
        return $user->can('purchasing.returns.approve');
    }

    public function post(User $user, SupplierReturn $m): bool
    {
        return $user->can('purchasing.returns.post');
    }
}
