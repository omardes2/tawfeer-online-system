<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حملات أتمتة التسويق (Phase 6 / ADR-046) — مستقلّة عن المزوّد (عبر MessagingManager).
 * حالة: draft → pending_approval → approved → active → paused → completed (+ archived).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->string('use_case', 40);   // abandoned_cart/post_delivery/birthday/back_in_stock/win_back/...
            $table->string('channel', 20)->default('whatsapp');
            $table->string('status', 20)->default('draft')->index();
            // آليّة الإطلاق: حدث (event) أو مجدول (scheduled) أو يدوي (manual).
            $table->string('trigger_type', 12)->default('event'); // event | scheduled | manual
            $table->string('trigger_event', 60)->nullable();       // اسم الحدث الدومين المُشترك
            $table->json('audience')->nullable();                  // فلاتر الجمهور
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('campaign_templates')->nullOnDelete();
            $table->text('body_ar')->nullable();                   // بديل مباشر للقالب
            $table->text('body_en')->nullable();
            $table->string('subject', 200)->nullable();
            $table->unsignedSmallInteger('frequency_cap_days')->default(0); // 0 = بلا حدّ
            $table->unsignedTinyInteger('quiet_start')->nullable();          // ساعة 0-23
            $table->unsignedTinyInteger('quiet_end')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'trigger_event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
