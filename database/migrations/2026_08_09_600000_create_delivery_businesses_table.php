<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حسابات «البزنس» لدى شركة التوصيل (Opost): الحساب الواحد قد يملك عدّة بزنس.
// تُجلَب/تُزامَن من المزوّد، ويُربَط كل مستخدم ببزنس تُدخَل طرود طلباته تحته.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_businesses', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->default('opost'); // مفتاح الـDriver
            $table->string('external_id', 100);               // معرّف البزنس لدى المزوّد
            $table->string('name', 200);
            $table->string('address_external_id', 100)->nullable(); // عنوان الالتقاط الافتراضي
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('raw')->nullable(); // الحمولة الخام من المزوّد (مرونة الحقول)
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_businesses');
    }
};
