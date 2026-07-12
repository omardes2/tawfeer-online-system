<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ تحوّلات حالة العمولة (Phase 4.2 / ADR-037) — **append-only**. كل اعتماد/استحقاق/
 * دفع/عكس يُدوَّن كحركة (from→to، المنفّذ، المرجع). مصدر تدقيق الحالة (لا تعديل صامت).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_entry_id')->constrained('commission_entries')->cascadeOnDelete();
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 80)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('commission_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_transitions');
    }
};
