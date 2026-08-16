<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عدد الطرود التي تُسلَّم لشركة التوصيل.
 *
 * كان الرقم المُرسَل مجموعَ كميات البنود: طلبٌ فيه 20 قطعة يقول للشركة «عندي 20
 * طردًا» وهو يُسلَّم في كيسٍ واحد — فتَرفضه (سقفها 12 طردًا) ويدور في حلقة
 * محاولات لا تنجح.
 *
 * والعدد الصحيح ليس عدد القطع ولا عدد الأصناف، بل **ما يستلمه السائق ويُمسَح
 * ضوئيًا** وعليه تُبنى المطالبة لو ضاع شيء. وهو واحدٌ في الغالبية العظمى من
 * الطلبات، فالافتراضي 1 ويُعدَّل عند الشحن في صناديق متعدّدة.
 *
 * حقلُ شحنٍ بحت: لا يمسّ كمية البنود ولا الفاتورة ولا المخزون ولا العمولات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('parcels_count')->default(1)->after('has_return');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('parcels_count');
        });
    }
};
