<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// جهات اتصال المورد (PHASE_2_DESIGN §10).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('position', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('supplier_id');
            $table->index(['supplier_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_contacts');
    }
};
