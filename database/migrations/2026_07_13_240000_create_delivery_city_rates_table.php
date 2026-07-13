<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أسعار توصيل شركة التوصيل لكل مدينة (نمط Opost: مدن + تكلفة توصيل/إرجاع لكل مدينة).
 * تُملأ المدن آليًا بالمزامنة من المزوّد (external_code)، وتُدار أسعار التوصيل/الإرجاع
 * محليًا. مزوّد التسعير المحلّي يقرأ من هنا عند حساب تكلفة الشحنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_city_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('external_code', 60)->nullable(); // معرّف المدينة لدى المزوّد (Opost city id)
            $table->string('name');                          // اسم المدينة كما يظهر
            $table->string('name_en')->nullable();
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('return_fee', 15, 2)->default(0);
            $table->string('currency', 3)->default('ILS');
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // فريد لكل (مزوّد + مدينة المزوّد) — تُحدَّث عند كل مزامنة بدل التكرار.
            $table->unique(['delivery_provider_id', 'external_code'], 'delivery_city_rate_provider_ext_unique');
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_city_rates');
    }
};
