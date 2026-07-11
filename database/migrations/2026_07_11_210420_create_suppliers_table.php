<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// الموردون (PHASE_2_DESIGN §9). governorate/city/currency أعمدة مؤجّلة الـFK.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedBigInteger('governorate_id')->nullable(); // FK مؤجّل
            $table->unsignedBigInteger('city_id')->nullable();        // FK مؤجّل
            $table->unsignedBigInteger('currency_id')->nullable();    // FK مؤجّل
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('is_active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
