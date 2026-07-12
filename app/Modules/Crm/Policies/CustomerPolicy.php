<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;
use App\Modules\Crm\Models\Customer;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.customers.view');
    }

    public function view(User $user, Customer $m): bool
    {
        return $user->can('crm.customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('crm.customers.create');
    }

    public function update(User $user, Customer $m): bool
    {
        return $user->can('crm.customers.update');
    }

    public function delete(User $user, Customer $m): bool
    {
        return $user->can('crm.customers.delete');
    }

    public function block(User $user, Customer $m): bool
    {
        return $user->can('crm.customers.block');
    }

    public function merge(User $user, Customer $m): bool
    {
        return $user->can('crm.customers.merge');
    }
}
