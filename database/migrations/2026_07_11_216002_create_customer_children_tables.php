<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// جداول العميل التابعة (ADR-030): هواتف متعددة، عناوين متعددة، جهات اتصال، ملاحظات.
return new class extends Migration
{
    public function up(): void
    {
        // هواتف متعددة + أساسي واحد (BR-CUST-05).
        Schema::create('customer_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('phone', 40); // مُطبَّع
            $table->string('label', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('customer_id');
            $table->index('phone'); // كشف التكرار عبر كل الأرقام (BR-CUST-05)
        });

        // عناوين متعددة + افتراضي واحد؛ يشير إلى الجغرافيا (BR-CUST-06، ADR-014).
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('recipient_name', 180)->nullable();
            $table->string('phone', 40)->nullable();
            $table->foreignId('governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->text('address_line')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('customer_id');
        });

        // جهات اتصال متعددة (كنمط supplier_contacts).
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 180);
            $table->string('position', 120)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        // ملاحظات (سجلّ زمني — append-only بلا updated_at).
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_phones');
    }
};
