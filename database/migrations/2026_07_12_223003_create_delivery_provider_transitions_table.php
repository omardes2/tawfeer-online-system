<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ انتقالات حالة المزوّد الخام (append-only، ADR-038 / المتطلّب: حفظ كل انتقال مزوّد بوقته).
 * منفصل تمامًا عن السجلّ القانوني. مفتاح idempotency فريد (provider, event_id) يمنع تكرار الـwebhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_provider_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('delivery_provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();
            $table->string('from_provider_status', 60)->nullable();
            $table->string('to_provider_status', 60);
            $table->string('canonical_status', 40)->nullable(); // القانوني المُعيَّن (قد يكون null إن لم يُعرَف)
            $table->string('event_id', 120)->nullable();         // معرّف حدث المزوّد (idempotency)
            $table->json('payload')->nullable();                 // الحمولة الخام (تخزين آمن)
            $table->timestamp('received_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('shipment_id');
            // منع تكرار نفس حدث المزوّد (تعدد NULL مسموح — الأحداث بلا معرّف لا تُقيَّد).
            $table->unique(['delivery_provider_id', 'event_id'], 'uniq_provider_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_provider_transitions');
    }
};
