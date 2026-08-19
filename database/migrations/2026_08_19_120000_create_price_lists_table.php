<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قوائم الأسعار — طبقة سعرٍ لفئةٍ من المشترين (تجار مثلًا).
 *
 * الأسعار الأربعة على المتغيّر (تكلفة/بيع/جملة/مسوّق) طبقاتٌ عامّة تنطبق على
 * الجميع؛ وهذه قائمةٌ **تُسنَد إلى أشخاص بعينهم**، فيشتري صاحبها بسعرها لا
 * بسعر الجملة.
 *
 * و`parent_id` هو ما يجعل تخصيص تاجرٍ بعينه ممكنًا بلا تكرار: قائمةٌ خاصّة به
 * تحمل الأصناف المختلفة عليه وحدها، وترث الباقي من القائمة العامّة. ولولاه
 * لَلَزِم نسخُ مئة صنف من أجل خمسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('code', 40)->nullable()->unique();
            // القائمة الأب: ما لم يُخصَّص في هذه القائمة يُقرأ منها.
            $table->foreignId('parent_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            // تقييد الحذف: سعرٌ يشير إلى متغيّر محذوف سعرٌ بلا معنى، وحذفه
            // الصامت يغيّر ما يشتري به التاجر بلا أن يعلم أحد.
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('price', 15, 2);
            $table->timestamps();

            $table->unique(['price_list_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
