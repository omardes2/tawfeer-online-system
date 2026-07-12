<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رسائل الحملات (Phase 6 / ADR-046) — سجلّ تنفيذ كامل: حالة تسليم/فشل، محاولات، idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('recipient', 190)->nullable();
            $table->text('body')->nullable();
            // queued | sent | failed | suppressed | skipped_consent | skipped_frequency | skipped_quiet
            $table->string('status', 24)->default('queued')->index();
            $table->string('provider_reference', 120)->nullable();
            $table->string('error', 300)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('is_test')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_messages');
    }
};
