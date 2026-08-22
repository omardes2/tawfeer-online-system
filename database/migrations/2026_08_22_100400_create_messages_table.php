<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رسائل المحادثة، واردةً وصادرة.
 *
 * `external_id` (وهو `wamid` لواتساب) **فريدٌ وهو أساس منع التكرار**: ميتا
 * تُعيد إرسال الـwebhook عند أي تأخّرٍ في الردّ، فبلا هذا الفهرس تُخزَّن الرسالة
 * مرّتين ويردّ الوكيل مرّتين على سؤالٍ واحد. والفهرس يجعل التكرار مستحيلًا في
 * قاعدة البيانات لا في الكود — فلا يُنقض بسباق تنفيذٍ متزامن.
 *
 * ويقبل `null` للرسائل الصادرة قبل أن يعود معرّفها من المنصّة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('external_id', 150)->nullable()->unique();
            $table->string('direction', 10);                 // inbound | outbound
            $table->string('sender_type', 20);               // customer | ai | agent | system
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('text');     // text | image | video | document | audio | template
            $table->text('body')->nullable();
            $table->string('media_path', 255)->nullable();
            $table->json('payload')->nullable();             // الحمولة الخام كما وصلت
            $table->string('delivery_status', 20)->default('queued');
            $table->string('failed_reason', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
