<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * حساب «مصاريف استيراد مستحقة» (2110) — الطرف الدائن الذي يوازن قيدَ فاتورة
 * الاستيراد حين يُدان المخزون بالتكلفة الشاملة بينما تُدان ذمّة المورد بسعرها
 * الحقيقي. مضافٌ إلى `ChartOfAccountsSeeder` أيضًا؛ وهنا لتضمن قواعدُ البيانات
 * القائمة وجودَه بمجرّد الترقية بلا إعادة زرع يدوية.
 */
return new class extends Migration
{
    private const CODE = '2110';

    public function up(): void
    {
        if (DB::table('accounts')->where('code', self::CODE)->exists()) {
            return;
        }

        $parentId = DB::table('accounts')->where('code', '2000')->value('id');
        if ($parentId === null) {
            return; // دليل الحسابات لم يُزرع بعد — يتكفّل به الزارع.
        }

        DB::table('accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'code' => self::CODE,
            'name' => 'مصاريف استيراد مستحقة',
            'name_en' => 'Accrued Import Costs',
            'type' => 'liability',
            'parent_id' => $parentId,
            'is_postable' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // لا يُحذف حسابٌ قد تكون عليه قيود — الحذف يترك القيود بلا حساب.
    }
};
