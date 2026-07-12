<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسويات المالية مع مزوّد التوصيل — مطابقة بيان المزوّد (Phase 4.6 / ADR-041).
 * حالة: draft → reconciled → posted → closed (+ cancelled). لا حذف — عكس (ADR-016).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('delivery_provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 20)->default('draft')->index();

            // المُبلَّغ من بيان المزوّد.
            $table->decimal('reported_cod_total', 15, 2)->default(0);
            $table->decimal('reported_fees_total', 15, 2)->default(0);
            $table->decimal('reported_deductions_total', 15, 2)->default(0);
            $table->decimal('reported_net', 15, 2)->default(0);

            // المحسوب من سطور التسوية (سجلّاتنا).
            $table->decimal('computed_cod_total', 15, 2)->default(0);
            $table->decimal('computed_fees_total', 15, 2)->default(0);
            $table->decimal('computed_deductions_total', 15, 2)->default(0);
            $table->decimal('computed_net', 15, 2)->default(0);
            $table->decimal('variance', 15, 2)->default(0); // فرق المُبلَّغ − المحسوب

            $table->string('notes', 1000)->nullable();
            $table->unsignedBigInteger('accounting_entry_id')->nullable()->index(); // ربط مرن (لا FK صارم)
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_settlements');
    }
};
