<?php

namespace App\Modules\Payment\Policies;

use App\Models\User;
use App\Modules\Payment\Models\Payment;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $m): bool
    {
        return $user->can('payments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payments.create');
    }

    public function capture(User $user, Payment $m): bool
    {
        return $user->can('payments.capture');
    }

    public function refund(User $user, Payment $m): bool
    {
        return $user->can('payments.refund');
    }
}
