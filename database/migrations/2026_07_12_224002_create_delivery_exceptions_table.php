<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * استثناءات التوصيل (Phase 4.3 / ADR-039): SLA + تصعيد + مسؤول + إعادة فتح/حلّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_exceptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('delivery_exception_categories');
            // open → in_progress → escalated → resolved → (reopened → ...)
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // المسؤول
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shipment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_exceptions');
    }
};
