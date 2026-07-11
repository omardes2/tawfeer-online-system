<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المستودعات (PHASE_2_DESIGN §7؛ ADR-003 تعدد المستودعات).
// governorate_id/city_id أعمدة مؤجّلة الـFK حتى إنشاء الجغرافيا (ADR-014).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 30);
            $table->string('type', 20)->default('main'); // main/transit/virtual/damaged
            $table->unsignedBigInteger('governorate_id')->nullable(); // FK مؤجّل
            $table->unsignedBigInteger('city_id')->nullable();        // FK مؤجّل
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('allow_negative')->default(false); // ADR-007a
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']); // فرادة مركّبة (ADR-004)
            $table->index('branch_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
