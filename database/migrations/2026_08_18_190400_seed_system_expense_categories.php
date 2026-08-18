<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تصنيفان يقابلان حسابَين يُرحّل عليهما النظام آليًا.
 *
 * «مصروف الشحن 5020» تكتبه رسومُ التوصيل، و«عمولات المسوّقين 5040» يكتبه صرفُ
 * الأرباح. لولا تصنيفٌ يقابلهما لأنشأ المستخدم تصنيفًا بالاسم نفسه على حسابٍ
 * جديد، فانقسم مصروفُ الشحن بين حسابين يحملان الاسم ذاته ولا يجتمعان في تقرير.
 *
 * `is_system` يمنع حذفهما أو تحويلهما إلى حسابٍ آخر؛ الاسم وحده يبقى قابلًا
 * للتعديل.
 */
return new class extends Migration
{
    /** رمز الحساب ⟵ [الاسم العربي، الإنجليزي، الترتيب] */
    private const CATEGORIES = [
        '5020' => ['مصروف الشحن', 'Shipping Expense', 10],
        '5040' => ['عمولات المسوّقين', 'Affiliate Commissions', 20],
    ];

    public function up(): void
    {
        foreach (self::CATEGORIES as $code => [$name, $nameEn, $order]) {
            $accountId = DB::table('accounts')->where('code', $code)->value('id');

            if ($accountId === null || DB::table('expense_categories')->where('account_id', $accountId)->exists()) {
                continue;
            }

            DB::table('expense_categories')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'name_en' => $nameEn,
                'account_id' => $accountId,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('expense_categories')->where('is_system', true)->delete();
    }
};
