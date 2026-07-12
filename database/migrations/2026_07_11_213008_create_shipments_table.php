<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// الشحنات (ADR-027). uuid + soft-delete + auditable. ترقيم SHP-{YYYY}-{seq}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 25)->default('not_shipped'); // مفتاح من shipment_statuses (ADR-017)
            $table->string('carrier_name', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();

            // لقطة العنوان + معرّفات مُعيَّنة (لا يُحسب السعر من الاسم — المتطلّب 9).
            $table->string('recipient_name', 180);
            $table->string('recipient_phone', 40);
            $table->text('address_text')->nullable();
            $table->foreignId('governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('shipping_zone_id')->nullable()->constrained('shipping_zones')->nullOnDelete();

            // لقطة التكلفة (لا تتغيّر تاريخيًا — المتطلّب 8).
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->string('cost_source', 20)->default('pending'); // provider_live/provider_synced/zone/manual/pending
            $table->string('cost_currency', 3)->nullable();

            // تكامل المزوّد (شحنة لمزوّد واحد).
            $table->foreignId('delivery_provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();
            $table->string('external_id', 80)->nullable();
            $table->string('external_code', 80)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 20)->nullable();

            // معالم دورة الحياة.
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->string('failure_reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
            $table->index('status');
            $table->index('tracking_number');
            $table->index('number');
            $table->index(['delivery_provider_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
