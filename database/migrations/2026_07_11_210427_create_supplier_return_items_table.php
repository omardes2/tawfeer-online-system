<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// بنود مرتجع المشتريات (ADR-025).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();

            $table->index('supplier_return_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_items');
    }
};
