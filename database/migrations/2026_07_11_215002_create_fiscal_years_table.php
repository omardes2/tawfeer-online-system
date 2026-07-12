<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// السنوات المالية والفترات (ADR-029، المتطلّب 7). تدعم تعدد السنوات دون إعادة تصميم.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 40);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 15)->default('open'); // open/closed
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->string('name', 40);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 15)->default('open'); // open/closed
            $table->timestamps();

            $table->index(['fiscal_year_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
