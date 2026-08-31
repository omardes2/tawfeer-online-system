<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ExpenseCategory;
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
            // السلفة أصلٌ لا مصروف: خرج المال وهو دَينٌ على الموظف يعود.
            // وقيدُها مصروفًا يُضخّم كلفة الشهر ويُخفي أصلًا للشركة، ثم يُقرأ
            // تسديدُها إيرادًا — فيظهر ربحٌ من إقراض الموظفين.
            ['1150', 'سلف الموظفين', 'asset', '1000'],
            ['1200', 'المخزون', 'asset', '1000'],
            ['1250', 'ضريبة المدخلات (قابلة للاسترداد)', 'asset', '1000'], // ضريبة المشتريات
            ['2010', 'ذمم الموردين', 'liability', '2000'],
            // مصاريف حُمّلت على البضاعة ولم تصل فواتيرها بعد (شحن بحري/جمارك/عمولة
            // مكتب). تُقيَّد دائنة عند ترحيل فاتورة الاستيراد، وتُطفأ بفاتورة
            // المصاريف حين تصل؛ ما يتبقّى فرقُ تقدير. رصيدٌ باقٍ = كونتينر لم يُغلق.
            ['2110', 'مصاريف استيراد مستحقة', 'liability', '2000'],
            ['2100', 'ضريبة مستحقة', 'liability', '2000'],
            // الرواتب: مصروفٌ يقع في شهر العمل والتزامٌ يُطفأ عند الصرف —
            // فبينهما يُقرأ في الميزانية ما على الشركة لموظفيها.
            ['2200', 'رواتب مستحقة', 'liability', '2000'],
            // ومخصّص نهاية الخدمة منفصلٌ عنه: التزامٌ يتراكم ولا يُدفَع حتى
            // تنتهي الخدمة. خلطُه بالرواتب المستحقّة يجعل التزام السنوات يبدو
            // راتبَ هذا الشهر.
            ['2210', 'مخصّص مكافأة نهاية الخدمة', 'liability', '2000'],
            ['3010', 'رأس المال', 'equity', '3000'],
            ['3020', 'أرباح مُبقاة', 'equity', '3000'],
            ['4010', 'إيراد المبيعات', 'revenue', '4000'],
            ['4020', 'إيراد الشحن', 'revenue', '4000'],
            ['4030', 'مردودات المبيعات', 'revenue', '4000'], // مقابل للإيراد (رصيده مدين)
            ['5020', 'مصروف الشحن', 'expense', '5000'],
            ['5030', 'الخصومات', 'expense', '5000'],
            ['5040', 'عمولات المسوّقين', 'expense', '5000'],
            ['5200', 'مصروف الرواتب والأجور', 'expense', '5000'],
            ['5210', 'مصروف مكافأة نهاية الخدمة', 'expense', '5000'],
            // المكافأة مصروفُ شهرها لا زيادةٌ في الراتب: تُمنح مرّةً فلا تدخل
            // العقد ولا يتراكم عليها مخصّص نهاية الخدمة.
            ['5220', 'مكافآت وحوافز', 'expense', '5000'],
            // ما يتبقّى في الحساب الوسيط عند إغلاق الشحنة: التقدير لا يطابق الفعلي
            // أبدًا، والفرق نتيجةُ فترة لا يُعاد به تسعير بضاعةٍ بِيعت.
            ['5050', 'فروق تقدير تكاليف الاستيراد', 'expense', '5000'],
            // فرق قيمة الدَّين بين يوم الفاتورة ويوم السداد — ليس دَينًا باقيًا
            // على المورد بل نتيجةَ تحرّك سعر الصرف.
            ['5060', 'فروق أسعار الصرف', 'expense', '5000'],
        ];
        foreach ($leaves as [$code, $name, $type, $parent]) {
            Account::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'parent_id' => $parents[$parent], 'is_postable' => true],
            );
        }

        // أبُ تصنيفات المصروفات التي يُعرّفها المستخدم (مراقب — الترحيل على
        // فروعه). مفصولٌ عن 5000 لأن تحته حسابات النظام (5050/5060) وهي نتائجُ
        // تقديرٍ لا مصروفٌ تشغيلي، فخلطُها يُفسد تقرير المصاريف.
        Account::query()->firstOrCreate(
            ['code' => '5100'],
            ['name' => 'مصاريف تشغيلية', 'name_en' => 'Operating Expenses', 'type' => 'expense', 'parent_id' => $parents['5000'], 'is_postable' => false],
        );

        // تصنيفان يقابلان حسابَين يُرحّل عليهما النظام آليًا. لولاهما لأنشأ
        // المستخدم تصنيفًا بالاسم نفسه على حسابٍ جديد، فانقسم مصروفُ الشحن بين
        // حسابين يحملان الاسم ذاته ولا يجتمعان في تقرير.
        $systemCategories = [
            ['5020', 'مصروف الشحن', 'Shipping Expense', 10],
            ['5040', 'عمولات المسوّقين', 'Affiliate Commissions', 20],
        ];
        foreach ($systemCategories as [$code, $name, $nameEn, $order]) {
            $account = Account::query()->where('code', $code)->first();
            if ($account) {
                ExpenseCategory::query()->firstOrCreate(
                    ['account_id' => $account->id],
                    ['name' => $name, 'name_en' => $nameEn, 'is_system' => true, 'is_active' => true, 'sort_order' => $order],
                );
            }
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
