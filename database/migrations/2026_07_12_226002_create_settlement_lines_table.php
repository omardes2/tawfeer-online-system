<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطور التسوية المالية (Phase 4.6 / ADR-041) — لكل طلب/شحنة: COD + رسوم + خصم + صافٍ،
 * مع مطابقة المُبلَّغ وكشف التباين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_settlement_id')->constrained('delivery_settlements')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->decimal('cod_amount', 15, 2)->default(0);        // COD محصّل (سجلّاتنا)
            $table->decimal('fee_amount', 15, 2)->default(0);        // رسوم التوصيل
            $table->decimal('deduction_amount', 15, 2)->default(0);  // خصومات/تسويات عامّة
            $table->decimal('net_amount', 15, 2)->default(0);        // cod − fee − deduction
            $table->decimal('reported_cod', 15, 2)->nullable();      // المُبلَّغ من المزوّد (للمطابقة)
            $table->boolean('matched')->default(false);
            $table->decimal('variance', 15, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['delivery_settlement_id', 'matched']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
    }
};
