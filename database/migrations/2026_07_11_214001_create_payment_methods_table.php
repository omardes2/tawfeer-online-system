<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// طرق الدفع (سجلّ المزوّدين — ADR-028). إضافة مزوّد = صفّ + Driver، دون لمس منطق الطلب/الدفع.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('code', 40)->unique(); // cod/bank_transfer/hyperpay/myfatoorah/stripe/paypal/moyasar
            $table->string('driver', 40)->default('null'); // مفتاح Driver في طبقة التكامل
            $table->string('type', 20)->default('online'); // offline (COD/تحويل) | online (بوابة)
            $table->boolean('is_active')->default(false);
            $table->json('config')->nullable(); // إعداد غير سرّي (الأسرار في .env)
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
