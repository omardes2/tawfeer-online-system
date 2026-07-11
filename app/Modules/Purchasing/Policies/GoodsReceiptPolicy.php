<?php

namespace App\Modules\Purchasing\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceipt;

class GoodsReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.receipts.view');
    }

    public function view(User $user, GoodsReceipt $m): bool
    {
        return $user->can('purchasing.receipts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.receipts.create');
    }

    public function update(User $user, GoodsReceipt $m): bool
    {
        return $user->can('purchasing.receipts.update');
    }

    public function delete(User $user, GoodsReceipt $m): bool
    {
        return $user->can('purchasing.receipts.delete');
    }

    public function post(User $user, GoodsReceipt $m): bool
    {
        return $user->can('purchasing.receipts.post');
    }
}
