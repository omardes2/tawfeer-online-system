<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use Illuminate\Database\Seeder;

/**
 * الخزينة والبنك الرئيسيان (Phase 7.1). كل خزينة/بنك مرتبط بحساب GL **طرفي قابل للترحيل**
 * فرعي تحت حساب المراقبة المناسب: الصندوق الرئيسي (1011-0001) تحت «حساب النقدية 1011»،
 * والبنك الرئيسي (1020-0001) تحت «الحسابات البنكية 1020». الرصيد الافتتاحي 0.
 */
class TreasurySeeder extends Seeder
{
    public function run(): void
    {
        $cashParent = Account::where('code', config('accounting.treasury.cash_account', '1011'))->first();
        $bankParent = Account::where('code', config('accounting.treasury.bank_account', '1020'))->first();

        if ($cashParent) {
            $cashGl = Account::query()->firstOrCreate(
                ['code' => config('accounting.treasury.cash_posting', '1011-0001')],
                ['name' => 'الصندوق الرئيسي', 'name_en' => 'Main Cashbox', 'type' => 'asset',
                    'parent_id' => $cashParent->id, 'is_postable' => true, 'currency' => 'SAR', 'is_active' => true],
            );
            Treasury::query()->firstOrCreate(
                ['code' => 'CB-MAIN'],
                ['name' => 'الصندوق الرئيسي', 'name_en' => 'Main Cashbox', 'type' => 'cash',
                    'gl_account_id' => $cashGl->id, 'currency' => 'SAR', 'is_active' => true, 'is_default' => true],
            );
        }

        if ($cashParent) {
            // صندوق الأونلاين: يستقبل تحصيلات COD عند وصول المبلغ لمحاسبة المندوب (in_accounting).
            $onlineGl = Account::query()->firstOrCreate(
                ['code' => '1011-0002'],
                ['name' => 'صندوق الأونلاين', 'name_en' => 'Online Cashbox', 'type' => 'asset',
                    'parent_id' => $cashParent->id, 'is_postable' => true, 'currency' => 'SAR', 'is_active' => true],
            );
            Treasury::query()->firstOrCreate(
                ['code' => 'CB-ONLINE'],
                ['name' => 'صندوق الأونلاين', 'name_en' => 'Online Cashbox', 'type' => 'cash',
                    'gl_account_id' => $onlineGl->id, 'currency' => 'SAR', 'is_active' => true, 'is_default' => false],
            );
        }

        if ($bankParent) {
            $bankGl = Account::query()->firstOrCreate(
                ['code' => '1020-0001'],
                ['name' => 'البنك الرئيسي', 'name_en' => 'Main Bank', 'type' => 'asset',
                    'parent_id' => $bankParent->id, 'is_postable' => true, 'currency' => 'SAR', 'is_active' => true],
            );
            Treasury::query()->firstOrCreate(
                ['code' => 'BNK-MAIN'],
                ['name' => 'البنك الرئيسي', 'name_en' => 'Main Bank', 'type' => 'bank',
                    'gl_account_id' => $bankGl->id, 'currency' => 'SAR', 'is_active' => true, 'is_default' => true],
            );
        }
    }
}
