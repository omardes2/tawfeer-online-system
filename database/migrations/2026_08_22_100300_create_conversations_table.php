<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المحادثة — وحدة العمل في الصندوق الموحّد.
 *
 * `ai_mode` ثلاثي لا ثنائي: `active` يردّ الوكيل، و`paused` أوقفه إنسانٌ مؤقتًا،
 * و`handed_off` سلّمه لموظفة. والفرق بين الأخيرين ليس تجميلًا — المحوَّلة لا
 * يعود إليها الوكيل إلّا بقرار موظفةٍ صريح، والمعلَّقة تعود بانتهاء سبب التعليق.
 *
 * و`order_id` هو ما يجعل قياس التحويل ممكنًا: كم محادثةً صارت طلبًا. وبدونه
 * تُشغّل وكيلًا ولا تعرف إن كان يبيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('channel_contact_id')->constrained('channel_contacts')->cascadeOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('conversation_statuses')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ai_mode', 20)->default('active');    // active | paused | handed_off
            $table->string('handoff_reason', 150)->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // ترتيب الصندوق: الأحدث أولًا ضمن الحالة.
            $table->index(['status_id', 'last_message_at']);
            $table->index(['assigned_user_id', 'last_message_at']);
            $table->index('ai_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
