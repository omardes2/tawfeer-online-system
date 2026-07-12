<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظات داخلية على استثناء توصيل (Phase 4.3 / ADR-039). append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_exception_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_exception_id')->constrained('delivery_exceptions')->cascadeOnDelete();
            $table->string('note', 1000);
            $table->string('kind', 20)->default('note'); // note | status_change | escalation
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('delivery_exception_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_exception_notes');
    }
};
