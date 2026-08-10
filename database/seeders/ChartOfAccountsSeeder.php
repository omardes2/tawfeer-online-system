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

        // النقدية والبنوك: حساب مراقبة رئيسي (1010) يتفرّع إلى «حساب النقدية 1011» (الخزائن
        // النقدية) و«الحسابات البنكية 1020» (البنوك). الحسابات الثلاثة مراقبة (غير قابلة للترحيل)؛
        // كل خزينة/بنك يُنشأ حسابًا طرفيًا فرعيًا تحتها (انظر TreasurySeeder).
        $cashBanks = Account::query()->firstOrCreate(
            ['code' => '1010'],
            ['name' => 'النقدية والبنوك', 'type' => 'asset', 'parent_id' => $parents['1000'], 'is_postable' => false],
        );
        $cashGroup = Account::query()->firstOrCreate(
            ['code' => '1011'],
            ['name' => 'حساب النقدية', 'type' => 'asset', 'parent_id' => $cashBanks->id, 'is_postable' => false],
        );
        $bankGroup = Account::query()->firstOrCreate(
            ['code' => '1020'],
            ['name' => 'الحسابات البنكية', 'type' => 'asset', 'parent_id' => $cashBanks->id, 'is_postable' => false],
        );

        // الحسابان الطرفيان الافتراضيان (الصندوق/البنك الرئيسيان) — قابلان للترحيل. تُربَط بهما
        // الخزينة/البنك الرئيسيان في TreasurySeeder، ويشير إليهما إعداد الترحيل «cash».
        Account::query()->firstOrCreate(
            ['code' => '1011-0001'],
            ['name' => 'الصندوق الرئيسي', 'name_en' => 'Main Cashbox', 'type' => 'asset',
                'parent_id' => $cashGroup->id, 'is_postable' => true, 'currency' => 'SAR'],
        );
        Account::query()->firstOrCreate(
            ['code' => '1020-0001'],
            ['name' => 'البنك الرئيسي', 'name_en' => 'Main Bank', 'type' => 'asset',
                'parent_id' => $bankGroup->id, 'is_postable' => true, 'currency' => 'SAR'],
        );

        $leaves = [
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
            ['4030', 'مردودات المبيعات', 'revenue', '4000'], // مقابل للإيراد (رصيده مدين)
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
