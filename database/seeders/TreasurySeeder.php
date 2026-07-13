<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use Illuminate\Database\Seeder;

/**
 * الخزينة والبنك الرئيسيان (Phase 7.1) — مرتبطان بحسابي GL القائمين (1010/1020).
 * الرصيد الافتتاحي 0 (يُضبط لاحقًا من اللوحة). الأرصدة تُشتقّ من القيود المُرحّلة.
 */
class TreasurySeeder extends Seeder
{
    public function run(): void
    {
        $cashGl = Account::where('code', config('accounting.treasury.cash_account', '1010'))->first();
        $bankGl = Account::where('code', config('accounting.treasury.bank_account', '1020'))->first();

        if ($cashGl) {
            Treasury::query()->firstOrCreate(
                ['code' => 'CB-MAIN'],
                ['name' => 'الصندوق الرئيسي', 'name_en' => 'Main Cashbox', 'type' => 'cash',
                    'gl_account_id' => $cashGl->id, 'currency' => 'SAR', 'is_active' => true, 'is_default' => true],
            );
        }

        if ($bankGl) {
            Treasury::query()->firstOrCreate(
                ['code' => 'BNK-MAIN'],
                ['name' => 'البنك الرئيسي', 'name_en' => 'Main Bank', 'type' => 'bank',
                    'gl_account_id' => $bankGl->id, 'currency' => 'SAR', 'is_active' => true, 'is_default' => true],
            );
        }
    }
}
