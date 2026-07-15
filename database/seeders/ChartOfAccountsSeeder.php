<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Database\Seeder;

/**
 * دليل حسابات أساسي + سنة مالية جارية (ADR-029). قابل للتوسّع من اللوحة.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // مجموعات رئيسية (غير قابلة للترحيل) ثم حسابات فرعية (قابلة للترحيل).
        $groups = [
            ['1000', 'الأصول', 'asset'],
            ['2000', 'الخصوم', 'liability'],
            ['3000', 'حقوق الملكية', 'equity'],
            ['4000', 'الإيرادات', 'revenue'],
            ['5000', 'المصروفات', 'expense'],
        ];
        $parents = [];
        foreach ($groups as [$code, $name, $type]) {
            $parents[$code] = Account::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_postable' => false],
            )->id;
        }

        $leaves = [
            ['1010', 'الصندوق', 'asset', '1000'],
            ['1020', 'البنك', 'asset', '1000'],
            ['1050', 'ذمم شركات التوصيل (COD قيد التحصيل)', 'asset', '1000'], // Phase 4.6 — COD clearing
            ['1100', 'ذمم العملاء', 'asset', '1000'],
            ['1200', 'المخزون', 'asset', '1000'],
            ['1250', 'ضريبة المدخلات (قابلة للاسترداد)', 'asset', '1000'], // ضريبة المشتريات
            ['2010', 'ذمم الموردين', 'liability', '2000'],
            ['2100', 'ضريبة مستحقة', 'liability', '2000'],
            ['3010', 'رأس المال', 'equity', '3000'],
            ['3020', 'أرباح مُبقاة', 'equity', '3000'],
            ['4010', 'إيراد المبيعات', 'revenue', '4000'],
            ['4020', 'إيراد الشحن', 'revenue', '4000'],
            ['5020', 'مصروف الشحن', 'expense', '5000'],
            ['5030', 'الخصومات', 'expense', '5000'],
            ['5040', 'عمولات المسوّقين', 'expense', '5000'],
        ];
        foreach ($leaves as [$code, $name, $type, $parent]) {
            Account::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'parent_id' => $parents[$parent], 'is_postable' => true],
            );
        }

        // تكلفة البضاعة المباعة — حساب رئيسي مستقلّ (نوع «تكلفة بضاعة»)، قابل للترحيل.
        Account::query()->firstOrCreate(
            ['code' => '6000'],
            ['name' => 'تكلفة البضاعة المباعة', 'type' => 'cost_of_goods', 'parent_id' => null, 'is_postable' => true],
        );

        // سنة مالية جارية (2026) + فتراتها الشهرية.
        $year = FiscalYear::query()->firstOrCreate(
            ['name' => '2026'],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open'],
        );

        if ($year->periods()->count() === 0) {
            for ($m = 1; $m <= 12; $m++) {
                $start = sprintf('2026-%02d-01', $m);
                $end = date('Y-m-t', strtotime($start));
                AccountingPeriod::create([
                    'fiscal_year_id' => $year->id,
                    'name' => sprintf('2026-%02d', $m),
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => 'open',
                ]);
            }
        }
    }
}
