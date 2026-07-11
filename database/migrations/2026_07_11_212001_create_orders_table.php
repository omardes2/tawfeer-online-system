<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// طلبات البيع (Phase 2.6، ADR-009/010/026). ترقيم SO-{YYYY}-{seq}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // لقطة العميل + معرّف مؤجّل بلا FK (يُربط في 2.10 — CRM).
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 180);
            $table->string('customer_phone', 40);
            $table->string('customer_email', 180)->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('channel', 20)->default('manual'); // web/manual/marketer/pos
            $table->string('status', 30)->default('draft'); // مفتاح من order_statuses (ADR-017)
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);      // مؤجّل الحساب (ADR-015)
            $table->decimal('shipping_total', 15, 2)->default(0); // مؤجّل (2.7)
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('warehouse_id');
            $table->index('status');
            $table->index('customer_phone');
            $table->index('assigned_to');
            $table->index('number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
