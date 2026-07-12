<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المناطق (PHASE_2_DESIGN §5، ADR-014). مرجعية: بلا uuid/soft-delete/auditable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('code', 20)->nullable();
            $table->string('postal_code', 15)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'name']);
            $table->index('city_id');
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
