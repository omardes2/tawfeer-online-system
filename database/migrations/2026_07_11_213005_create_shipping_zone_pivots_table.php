<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// جدولا ربط مناطق الشحن بالمدن/المناطق (PHASE_2_DESIGN §6، كثير-لكثير).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zone_city', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();

            $table->unique(['shipping_zone_id', 'city_id']);
        });

        Schema::create('shipping_zone_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();

            $table->unique(['shipping_zone_id', 'area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zone_area');
        Schema::dropIfExists('shipping_zone_city');
    }
};
