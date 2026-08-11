<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use Illuminate\Database\Migrations\Migration;

/**
 * خزينة «صندوق الأونلاين» (CB-ONLINE): يستقبل تحصيلات COD لحظة وصول المبلغ لمحاسبة
 * المندوب (Opost: in_accounting) — مدين الصندوق / دائن «ذمم شركة التوصيل 1050».
 * عبر migration (لا seeder) لأن النشر يشغّل migrate فقط؛ التثبيت الجديد يغطّيه
 * TreasurySeeder. حارس وجود الأب يجعلها آمنة على قاعدة فارغة (الاختبارات).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cashParent = Account::where('code', config('accounting.treasury.cash_account', '1011'))->first();
        if (! $cashParent) {
            return; // قاعدة فارغة (قبل الـseed) — يتكفّل TreasurySeeder بالإنشاء.
        }

        $gl = Account::query()->firstOrCreate(
            ['code' => '1011-0002'],
            ['name' => 'صندوق الأونلاين', 'name_en' => 'Online Cashbox', 'type' => 'asset',
                'parent_id' => $cashParent->id, 'is_postable' => true, 'currency' => 'SAR', 'is_active' => true],
        );

        Treasury::query()->firstOrCreate(
            ['code' => 'CB-ONLINE'],
            ['name' => 'صندوق الأونلاين', 'name_en' => 'Online Cashbox', 'type' => 'cash',
                'gl_account_id' => $gl->id, 'currency' => 'SAR', 'is_active' => true, 'is_default' => false],
        );
    }

    public function down(): void
    {
        // لا حذف: قد تكون على الخزينة قيود مُرحّلة.
    }
};
