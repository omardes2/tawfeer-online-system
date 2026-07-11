<?php

namespace App\Modules\Purchasing\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\Supplier;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.suppliers.view');
    }

    public function view(User $user, Supplier $m): bool
    {
        return $user->can('purchasing.suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.suppliers.create');
    }

    public function update(User $user, Supplier $m): bool
    {
        return $user->can('purchasing.suppliers.update');
    }

    public function delete(User $user, Supplier $m): bool
    {
        return $user->can('purchasing.suppliers.delete');
    }
}
