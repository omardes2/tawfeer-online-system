<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قوالب رسائل التسويق (Phase 6 / ADR-046) — عربي/إنجليزي + متغيّرات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('channel', 20)->default('whatsapp'); // whatsapp/email/sms/internal
            $table->string('subject', 200)->nullable();
            $table->text('body_ar');
            $table->text('body_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_templates');
    }
};
