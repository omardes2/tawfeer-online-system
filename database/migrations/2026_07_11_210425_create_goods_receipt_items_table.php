<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// بنود إيصال الاستلام (ADR-025).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_received', 15, 3);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('landed_unit_cost', 15, 4)->nullable(); // بعد توزيع التكلفة المحمّلة
            $table->timestamps();

            $table->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
