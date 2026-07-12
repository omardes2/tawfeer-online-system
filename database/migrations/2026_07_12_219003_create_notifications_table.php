<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مركز الإشعارات (Phase 3.4 / ADR-035): جدول إشعارات Laravel القياسي (قناة database)
 * كصندوق داخل التطبيق. القنوات الخارجية (WhatsApp/Email/Push) تُوجَّه مستقبلًا عبر
 * طبقة المراسلة القائمة (MessagingManager — ADR-030) دون إعادة تصميم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
