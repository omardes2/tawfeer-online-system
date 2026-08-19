<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعلانٌ واحد بميزانيةٍ واحدة لعدّة أصناف.
 *
 * كان مفتاح الصرف (يوم × قناة × صنف) و`product_id` إلزاميًّا، فلم يكن للإعلان
 * المشترك مكانٌ في النموذج: يُقسَّم يدويًّا بلا أثرٍ يقول إنه كان إعلانًا واحدًا،
 * أو — في المزامنة — يقع صرفُه كلُّه على الصنف الوحيد المربوط، فيبدو ذلك الصنف
 * خاسرًا ويبدو إخوته «بلا إعلان» وهم يُعلَن عليهم من الميزانية نفسها.
 *
 * الصفّ المشترك يترك `product_id` فارغًا وتُسجَّل أصنافه في جدول الربط. وترك
 * الفراغ لا يكسر الفهرس الفريد: MySQL يسمح بتكرار NULL فيه، فتتعدّد الإعلانات
 * المشتركة في اليوم الواحد — وهو المطلوب.
 *
 * والصفوف القائمة لا تُمَسّ: صفٌّ بصنفٍ واحد يبقى كما هو ويُقرأ كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_daily_spends', function (Blueprint $table) {
            // عنوان يميّز الإعلان المشترك عن غيره في اليوم نفسه.
            $table->string('label', 120)->nullable()->after('product_id');
        });

        // SQLite لا يعدّل عمودًا بمفتاحٍ أجنبي في مكانه، والتغيير هنا رفعُ قيد
        // `NOT NULL` وحده — يُترك للـDoctrine عبر `change()` كما في بقية الهجرات.
        Schema::table('ad_daily_spends', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
        });

        Schema::create('ad_daily_spend_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_daily_spend_id')->constrained('ad_daily_spends')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ad_daily_spend_id', 'product_id'], 'ad_spend_products_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_daily_spend_products');

        Schema::table('ad_daily_spends', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
