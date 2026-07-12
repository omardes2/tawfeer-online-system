<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مكوّنات رسوم الشحنة (Phase 4.3 / ADR-039) — مستقلّة عن المزوّد وقابلة للتوسّع.
 * أنواع: delivery/cod/oversize/overweight/remote_area/return/exchange/retry/manual/discount.
 * المالك: business/customer/provider؛ المصدر: quoted/charged/actual/manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_fee_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('owner', 20)->default('business');
            $table->string('source', 20)->default('manual');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shipment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_fee_components');
    }
};
