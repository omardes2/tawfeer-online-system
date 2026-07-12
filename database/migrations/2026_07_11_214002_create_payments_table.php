<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المدفوعات (ADR-028). سجلّ دفع مقابل طلب عبر طريقة دفع. ترقيم PAY-{YYYY}-{seq}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->string('currency', 3)->nullable();
            // حالة سجلّ الدفع: pending/processing/paid/failed/cancelled/refunded/partially_refunded
            $table->string('status', 20)->default('pending');
            // مرجع/بيانات المزوّد (بنية عامة — المتطلّب 5).
            $table->string('provider_reference', 120)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
            $table->index('status');
            $table->index('number');
            $table->index(['payment_method_id', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
