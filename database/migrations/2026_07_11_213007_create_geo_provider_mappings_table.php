<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تعيين الكيانات الجغرافية المحلية إلى مزوّدين خارجيين (ADR-027).
// المحلي مرجع النظام؛ تعيين متعدد المزوّدين لكل سجلّ محلي (polymorphic).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_provider_mappings', function (Blueprint $table) {
            $table->id();
            $table->morphs('mappable'); // Governorate/City/Area/ShippingZone
            $table->foreignId('delivery_provider_id')->constrained('delivery_providers')->cascadeOnDelete();
            $table->string('external_id', 80)->nullable();
            $table->string('external_code', 80)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 20)->default('pending'); // pending/synced/stale/conflict
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mappable_type', 'mappable_id', 'delivery_provider_id'], 'geo_map_unique');
            $table->index(['delivery_provider_id', 'external_id'], 'geo_map_provider_ext');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_provider_mappings');
    }
};
