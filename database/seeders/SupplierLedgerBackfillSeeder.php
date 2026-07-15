<?php

namespace Database\Seeders;

use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Database\Seeder;

/**
 * إنشاء حساب فرعي في دليل الحسابات للموردين القدامى الذين أُنشئوا قبل هذه الميزة.
 * idempotent — يتخطّى من له حساب. يُشغَّل مرّة على السيرفر:
 *   php artisan db:seed --class=SupplierLedgerBackfillSeeder --force
 */
class SupplierLedgerBackfillSeeder extends Seeder
{
    public function run(SupplierService $suppliers): void
    {
        Supplier::whereNull('gl_account_id')->orderBy('id')
            ->chunkById(200, function ($chunk) use ($suppliers) {
                foreach ($chunk as $supplier) {
                    $suppliers->ensureLedgerAccount($supplier);
                }
            });
    }
}
