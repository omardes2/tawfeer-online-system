<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// إيصالات استلام البضاعة (GRN — ADR-025). ترقيم GRN-{YYYY}-{seq}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft/posted/cancelled
            $table->decimal('additional_cost', 15, 4)->default(0); // تكلفة محمّلة (BR-PUR-06)
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('purchase_order_id');
            $table->index('status');
            $table->index('number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
