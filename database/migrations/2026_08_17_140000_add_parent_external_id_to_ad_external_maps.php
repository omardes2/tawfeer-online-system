<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الحملة الأمّ لكل مجموعة إعلانية.
 *
 * الجدول كان يعرف «هذه المجموعة تخصّ الصنف 47» و«هذه الحملة تخصّ صفحة توفير»،
 * ولا يعرف أن تلك المجموعة داخل تلك الحملة — فكان الطيّار عاجزًا عن الإجابة عن
 * السؤال الوحيد الذي يحتاجه: **أيّ مجموعةٍ أوقِف من أجل (صنفٍ على صفحة)؟**
 *
 * والقيمة موجودة أصلًا في كل صفّ نتائج (`campaign_id` بجانب `adset_id`)، فتُلتقط
 * أثناء المزامنة بلا نداءٍ إضافي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_external_maps', function (Blueprint $table) {
            $table->string('parent_external_id', 64)->nullable()->after('external_name');
            $table->index(['provider', 'parent_external_id'], 'ad_external_maps_parent');
        });
    }

    public function down(): void
    {
        Schema::table('ad_external_maps', function (Blueprint $table) {
            $table->dropIndex('ad_external_maps_parent');
            $table->dropColumn('parent_external_id');
        });
    }
};
