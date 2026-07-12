<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جلسة إتمام الشراء متعدّدة الخطوات (Phase 3.2 / ADR-033). تراكم بيانات الإتمام
 * (لقطة الشحن + طريقة الدفع) قبل الإنشاء الذرّي النهائي للطلب. جدول إضافي فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_token')->nullable(); // هوية الضيف
            $table->string('status', 20)->default('pending'); // pending / placed / abandoned
            $table->string('customer_name', 180)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 180)->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('payment_method_code', 40)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('session_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
    }
};
