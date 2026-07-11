<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// الحالات القابلة للإدارة (المبدأ 10): طلب/دفع/شحن — تُدار من اللوحة لا enum مغلق.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['order_statuses', 'payment_statuses', 'shipment_statuses'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('key');
                $table->string('name');
                $table->string('color', 32)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_final')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('key');
                $table->index('is_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_statuses');
        Schema::dropIfExists('payment_statuses');
        Schema::dropIfExists('order_statuses');
    }
};
