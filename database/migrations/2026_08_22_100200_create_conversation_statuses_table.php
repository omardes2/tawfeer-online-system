<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حالات المحادثة القابلة للإدارة (المبدأ ١٠) — بنفس بنية `order_statuses`.
 *
 * لا `enum` مغلق: فرقُ خدمةٍ ينمو يُضيف حالاتٍ من اللوحة («بانتظار المخزون»،
 * «بانتظار الدفع») بلا نشر كود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_statuses', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('conversation_statuses');
    }
};
