<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// دفتر المخزون append-only مع رصيد جارٍ وWAC (PHASE_2_DESIGN §23، ADR-008/020).
// لا updated_at ولا deleted_at — سجلّ ثابت.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type', 30);
            $table->string('bucket', 20)->default('on_hand');
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('qty_change', 15, 3);
            $table->decimal('balance_after', 15, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('wac_after', 15, 4)->default(0);
            $table->decimal('value_change', 15, 4)->default(0);
            $table->decimal('balance_value_after', 15, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['variant_id', 'warehouse_id', 'created_at']);
            $table->index('movement_id');
            $table->index(['reference_type', 'reference_id']);
            $table->index('movement_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger');
    }
};
