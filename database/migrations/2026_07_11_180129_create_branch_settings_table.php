<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// إعدادات على مستوى الفرع تكمّل جدول settings العام (PHASE_2_DESIGN §2، المبدأ 9).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('key', 150);
            $table->json('value')->nullable();
            $table->string('group', 60)->default('general');
            $table->string('type', 30)->default('string');
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
            $table->index(['branch_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settings');
    }
};
