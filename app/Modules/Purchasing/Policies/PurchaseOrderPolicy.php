<?php

namespace App\Modules\Purchasing\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.orders.view');
    }

    public function view(User $user, PurchaseOrder $m): bool
    {
        return $user->can('purchasing.orders.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.orders.create');
    }

    public function update(User $user, PurchaseOrder $m): bool
    {
        return $user->can('purchasing.orders.update');
    }

    public function delete(User $user, PurchaseOrder $m): bool
    {
        return $user->can('purchasing.orders.delete');
    }

    public function approve(User $user, PurchaseOrder $m): bool
    {
        return $user->can('purchasing.orders.approve');
    }

    public function cancel(User $user, PurchaseOrder $m): bool
    {
        return $user->can('purchasing.orders.cancel');
    }

    public function close(User $user, PurchaseOrder $m): bool
    {
        return $user->can('purchasing.orders.close');
    }
}
