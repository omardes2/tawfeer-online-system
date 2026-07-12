<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ استيعاب أحداث المزوّد الخام (Phase 4.3 / ADR-039): webhook + مزامنة.
 * تسجيل كامل لكل محاولة (بما فيها المكرّرة/الفاشلة/غير المتحقّقة) — منفصل عن سجلّ
 * الانتقالات الفعلية (`delivery_provider_transitions`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();
            $table->string('provider_code', 40)->nullable();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('channel', 12)->default('webhook'); // webhook | sync
            $table->string('event_id', 120)->nullable();
            $table->string('external_id', 120)->nullable();
            $table->string('provider_status', 60)->nullable();
            $table->boolean('signature_valid')->nullable();     // null = لم يُتحقَّق (لا سرّ)
            // received | processed | duplicate | failed | unverified | inconsistent | ignored
            $table->string('status', 20)->default('received')->index();
            $table->json('payload')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider_code', 'event_id']);
            $table->index(['shipment_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_provider_events');
    }
};
