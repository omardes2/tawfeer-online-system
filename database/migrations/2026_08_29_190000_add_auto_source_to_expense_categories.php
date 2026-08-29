<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وسمُ التصنيف الذي **تحتسبه الميزانية من مصدره** لا من سندات الصرف.
 *
 * ## المشكلة
 *
 * قائمة الأرباح والخسائر تقرأ الإعلانات من جدول الصرف الإعلاني، والعمولات من
 * دفتر العمولات — استحقاقًا لا صرفًا. ثم تجمع فوقهما **كلَّ** سندات الصرف
 * بتصنيفاتها. فلو أنشأ المستخدم سند مصروفٍ بتصنيف «إعلانات» جُمع الرقم مرّتين:
 * مرّةً من جدوله ومرّةً من سنده، بلا ما يكشف الازدواج.
 *
 * ## الحلّ
 *
 * `auto_source` يقول: «هذا التصنيف محتسَبٌ من مصدره». فتُستثنى سنداتُه من إجمالي
 * المصاريف، وتبقى ظاهرةً للعِلم — فيستطيع المستخدم تسجيل الدفعة النقدية في
 * مكانها الطبيعي بلا أن يُفسد الرقم.
 *
 * ولا يُمنع الإنشاء: الدفعة واقعةٌ حقيقية تُسجَّل، والخطأ في **عدّها مرّتين** لا
 * في تسجيلها.
 *
 * القيمة نصٌّ لا مفتاحٌ أجنبيّ: مصادر الاحتساب أسماءٌ في الكود (`ads`،
 * `commissions`، `payroll`) لا صفوفٌ في جدول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('auto_source', 32)->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('auto_source');
        });
    }
};
