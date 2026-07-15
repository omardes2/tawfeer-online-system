<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ترقية حساب «تكلفة البضاعة المباعة» ليكون حسابًا رئيسيًا مستقلًّا برمز 6000
 * ونوع «تكلفة بضاعة» (cost_of_goods) بدل كونه فرعيًا تحت المصروفات برمز 5010.
 *
 * يحوّل السجلّ القائم في مكانه (نفس account_id) فتبقى قيوده وربط إعدادات الترحيل
 * سليمة. idempotent: على قاعدة جديدة (قبل البذر) لا يجد 5010 فلا يفعل شيئًا،
 * ثم يُنشئه البذر مباشرةً برمز 6000.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cogs = DB::table('accounts')->where('code', '5010')->first();
        if ($cogs) {
            DB::table('accounts')->where('id', $cogs->id)->update([
                'code' => '6000',
                'name' => 'تكلفة البضاعة المباعة',
                'type' => 'cost_of_goods',
                'parent_id' => null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $parentId = DB::table('accounts')->where('code', '5000')->value('id');
        DB::table('accounts')->where('code', '6000')->update([
            'code' => '5010',
            'type' => 'expense',
            'parent_id' => $parentId,
            'updated_at' => now(),
        ]);
    }
};
