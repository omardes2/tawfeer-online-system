<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// وحدات القياس مع تحويل بين الوحدات (PHASE_2_DESIGN §13). مرجعي: لا uuid/soft-delete.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('name_en', 60)->nullable();
            $table->string('code', 20)->unique();
            $table->string('symbol', 20)->nullable();
            $table->foreignId('base_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('conversion_factor', 15, 6)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('base_unit_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
