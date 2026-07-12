<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Modules\Crm\Models\Customer;

/**
 * حلّ عميل المستخدم الحالي لصفحات الحساب (Phase 3.4). مستخدم مُسجَّل كعميل يملك سجلًّا.
 */
trait InteractsWithCustomer
{
    protected function currentCustomer(): Customer
    {
        $customer = Customer::where('user_id', auth()->id())->first();
        abort_if($customer === null, 403);

        return $customer;
    }
}
