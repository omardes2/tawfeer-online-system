<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// سلة التسوّق (Phase 3.1، ADR-031). سلة نشطة واحدة لكل مستخدم؛ تُحوَّل إلى طلب عند الدفع.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_token', 64)->nullable(); // سلات الضيوف (تكامل مستقبلي)
            $table->string('status', 15)->default('active'); // active/converted/abandoned
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('session_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
