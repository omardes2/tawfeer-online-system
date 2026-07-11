<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مواقع التخزين داخل المستودع — شجري (PHASE_2_DESIGN §8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('warehouse_locations')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 120)->nullable();
            $table->string('type', 20)->default('bin'); // zone/rack/shelf/bin
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
            $table->index('warehouse_id');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');
    }
};
