<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبات المرتجعات والاستبدال — RMA (Phase 4.4 / ADR-040، ADR-011، BR-RET-*).
 * سير عمل موافقات: مبيعات → مشرف → مستودع → قرار نهائي. لا حذف — عكس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // return | exchange | replacement
            $table->string('type', 20);
            // wrong_item | damaged | missing_item | customer_refused | delivery_company_issue | internal_warehouse_mistake | changed_mind | other
            $table->string('reason_code', 40);
            // refund | no_refund | store_credit | replacement
            $table->string('resolution', 20);
            $table->string('scope', 12)->default('partial'); // full | partial (مشتقّ، للتقارير)
            // return_request → approved → received → inspected → completed (+rejected/cancelled)
            $table->string('status', 20)->default('return_request')->index();
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->decimal('price_difference', 15, 2)->default(0); // فرق سعر الاستبدال (+/−)
            $table->foreignId('refund_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('linked_shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('notes', 1000)->nullable();
            // مسار الموافقة (من فعل كل مرحلة).
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
    }
};
