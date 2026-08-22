<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ استدعاء النموذج — **append-only**: لا تعديل ولا حذف.
 *
 * وكيلٌ يحادث الزبائن باسم الشركة يجب أن يُسأل: ماذا قال؟ ولماذا؟ وبكم؟ وسجلٌّ
 * يُعدَّل لا يُجيب — لأن أوّل ما يُمحى هو الاستدعاء الذي أخطأ.
 *
 * و`cost` بـ(15,4) وحدها في هذا الموديول: تكلفة API بالدولار بكسورٍ دقيقة
 * (0.0032$)، وهي ليست من نقود المتجر فلا تُقاس بمعيار `(15,2)`.
 *
 * `created_at` وحده بلا `updated_at`: صفٌّ لا يُحدَّث لا يحتاج عمود تحديث،
 * ووجودُه يوحي بأنه قابل للتعديل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            // الرسائل المجمّعة التي أطلقت الاستدعاء — بها يُفهم على أيّ سؤالٍ ردّ.
            $table->json('trigger_message_ids')->nullable();
            $table->string('model', 50);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 15, 4)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('outcome', 20);        // replied | escalated | failed | silent
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
