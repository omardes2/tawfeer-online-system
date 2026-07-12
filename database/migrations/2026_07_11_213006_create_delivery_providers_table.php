<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// سجلّ شركات التوصيل (ADR-027). المزوّد يُوصَل حصريًا عبر طبقة التكامل (المبدأ 13).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->string('driver', 40)->default('null'); // مفتاح Driver في طبقة التكامل
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable(); // إعداد غير سرّي (الأسرار في .env)
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_providers');
    }
};
