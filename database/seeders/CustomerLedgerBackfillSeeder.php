<?php

namespace Database\Seeders;

use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use Illuminate\Database\Seeder;

/**
 * إنشاء حساب فرعي في دليل الحسابات للعملاء القدامى الذين أُنشئوا قبل هذه الميزة.
 * idempotent — يتخطّى من له حساب. يُشغَّل مرّة على السيرفر:
 *   php artisan db:seed --class=CustomerLedgerBackfillSeeder --force
 */
class CustomerLedgerBackfillSeeder extends Seeder
{
    public function run(CustomerService $customers): void
    {
        Customer::whereNull('gl_account_id')->orderBy('id')
            ->chunkById(200, function ($chunk) use ($customers) {
                foreach ($chunk as $customer) {
                    $customers->ensureLedgerAccount($customer);
                }
            });
    }
}
