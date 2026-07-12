<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فئات استثناءات التوصيل القابلة للضبط (Phase 4.3 / ADR-039) — SLA وقواعد التصعيد لكل فئة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_exception_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sla_hours')->nullable();            // مهلة الحلّ
            $table->unsignedSmallInteger('escalate_after_hours')->nullable(); // التصعيد بعد
            $table->string('escalate_to_role', 50)->nullable();               // دور التصعيد
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_exception_categories');
    }
};
