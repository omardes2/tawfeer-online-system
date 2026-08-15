<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * حساب «فروق تقدير تكاليف الاستيراد» (5050) — مقصده ما يتبقّى في الحساب الوسيط
 * عند إغلاق الشحنة: التقدير لا يطابق الفواتير الفعلية أبدًا، والفرق نتيجةُ فترةٍ
 * لا يُعاد به تسعير بضاعةٍ بِيعت.
 *
 * مضافٌ إلى `ChartOfAccountsSeeder` أيضًا؛ وهنا لتضمن قواعدُ البيانات القائمة
 * وجودَه بالترقية بلا إعادة زرع يدوية.
 */
return new class extends Migration
{
    private const CODE = '5050';

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
            'name' => 'فروق تقدير تكاليف الاستيراد',
            'name_en' => 'Import Cost Estimation Variance',
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
