<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عروض الكمّية — «اشترِ 5 قطع بـ100».
 *
 * **على الصنف لا على المتغيّر.** الزبون يشتري خمس قطعٍ بمقاساتٍ مختلفة ويعدّها
 * عرضًا واحدًا، ولو كان العرض على المتغيّر لوجب أن تكون الخمس بمقاسٍ واحد —
 * وهو ليس ما يفعله أحد. فالكمّية تُجمع عبر متغيّرات الصنف، والسعر يطال كلَّها.
 *
 * ويُخزَّن **السعر الإجمالي** لا سعر القطعة: التاجر يفكّر ويعلن بـ«خمس بمئة»،
 * وسعر القطعة (20) مشتقٌّ منه للعرض. والعكس كان سيُدخل كسورًا لا تُعلَن —
 * «ثلاث بمئة» تعطي 33.333 للقطعة، وضربُها في ثلاثة لا يعود مئةً بالضبط.
 *
 * ولا يُحرَس `total_price` بأن يكون أقلّ من السعر العادي: صاحب المتجر قد يبني
 * عرضًا على صنفٍ رفع سعره، والمنعُ هنا يُقحم النظام في قرار تسعيرٍ ليس له.
 * التحذير في الشاشة، والقرار له.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // أقلّ كمّية يبدأ عندها العرض — وهي مفتاحه.
            $table->unsignedSmallInteger('min_qty');
            $table->decimal('total_price', 15, 2);

            // نصّ اختياري يظهر على البطاقة («عرض التوفير»)؛ وبغيره يُركَّب آليًّا.
            $table->string('label', 60)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // عرضٌ واحد لكل كمّية: عرضان بالكمّية نفسها يجعلان السعر رهنَ ترتيبٍ
            // عشوائي، وهو خطأٌ لا يظهر إلّا حين يشتكي زبون.
            $table->unique(['product_id', 'min_qty'], 'product_offers_qty_unique');
            $table->index(['product_id', 'is_active'], 'product_offers_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_offers');
    }
};
