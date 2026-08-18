<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «مصاريف تشغيلية 5100» — أبُ تصنيفات المصروفات التي يُعرّفها المستخدم.
 *
 * حسابٌ مراقب (غير قابل للترحيل) تحت «المصروفات 5000»، تُفتح تحته حساباتُ
 * التصنيفات تلقائيًا. ولمَ أبٌ جديد لا 5000 نفسه: تحت 5000 تعيش حسابات النظام
 * — فروق تقدير الاستيراد (5050) وفروق الصرف (5060) — وهي نتائجُ تقديرٍ لا
 * مصروفٌ أُنفق. خلطُها بتصنيفات المستخدم يجعل تقرير «مصاريفي التشغيلية»
 * يبتلعها فيصير رقمًا لا يُقارَن بشهرٍ آخر.
 */
return new class extends Migration
{
    private const CODE = '5100';

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
            'name' => 'مصاريف تشغيلية',
            'name_en' => 'Operating Expenses',
            'type' => 'expense',
            'parent_id' => $parentId,
            'is_postable' => false, // الترحيل على التصنيفات الفرعية وحدها.
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // لا يُحذف حسابٌ قد تكون تحته حسابات وعليها قيود.
    }
};
