<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حجوزات المخزون (PHASE_2_DESIGN §25، ADR-009). order_id بلا FK (مؤجّل — Phase 3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();      // FK مؤجّل (Phase 3)
            $table->unsignedBigInteger('order_item_id')->nullable(); // مؤجّل
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('qty', 15, 3);
            $table->string('status', 20)->default('active'); // active/released/consumed/expired
            $table->timestamp('reserved_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['variant_id', 'warehouse_id', 'status']);
            $table->index('status');
            $table->index('order_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
