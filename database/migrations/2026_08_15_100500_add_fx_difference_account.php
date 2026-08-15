<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * حساب «فروق أسعار الصرف» (5060) — الفرق بين قيمة الدَّين يوم الفاتورة وقيمته يوم
 * السداد. تُشترى البضاعة بدولارٍ سعرُه 3.65 ويُسدَّد بدولارٍ سعرُه 3.70، فتخرج من
 * الخزينة شواكلُ أكثر ممّا قُيّد على المورد: الفارق ليس دَينًا باقيًا بل خسارةَ
 * صرفٍ تُسجَّل نتيجةً.
 *
 * مضافٌ إلى `ChartOfAccountsSeeder` أيضًا؛ وهنا لتضمن قواعدُ البيانات القائمة
 * وجودَه بالترقية بلا إعادة زرع يدوية.
 */
return new class extends Migration
{
    private const CODE = '5060';

    public function up(): void
    {
        if (DB::table('accounts')->where('code', self::CODE)->exists()) {
            return;
        }

        $parentId = DB::table('accounts')->where('code', '5000')->value('id');
        if ($parentId === null) {
            return; // دليل الحسابات لم يُزرع بعد — يتكفّل به الزارع.
        }

        DB::table('accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'code' => self::CODE,
            'name' => 'فروق أسعار الصرف',
            'name_en' => 'Foreign Exchange Differences',
            'type' => 'expense',
            'parent_id' => $parentId,
            'is_postable' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // لا يُحذف حسابٌ قد تكون عليه قيود.
    }
};
