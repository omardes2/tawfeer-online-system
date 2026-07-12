<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// دليل الحسابات (ADR-029/016، BR-ACC-05). شجرة حسابات؛ الأرصدة تُشتقّ من الدفتر لا تُخزَّن.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('name_en', 150)->nullable();
            $table->string('type', 20); // asset/liability/equity/revenue/expense
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_postable')->default(true); // أوراق الشجرة فقط تقبل القيود
            $table->string('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('parent_id');
            $table->index(['is_active', 'is_postable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
