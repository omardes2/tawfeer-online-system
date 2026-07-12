<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المحافظات (PHASE_2_DESIGN §3، ADR-014). مرجعية: بلا uuid/soft-delete/auditable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('code', 20)->nullable();
            $table->char('country_code', 2)->default('SA');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_code', 'code']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governorates');
    }
};
