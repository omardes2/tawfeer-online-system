<?php

namespace App\Jobs;

use App\Modules\AiAgent\Support\MessageBuffer;
use App\Modules\Messaging\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ردّ الوكيل على محادثةٍ بعد انتهاء مهلة التجميع.
 *
 * تحمل **رمز الجيل** الذي جُدولت به: إن وصلت رسالةٌ بعدها فقد صار لها جيلٌ
 * أحدث، فتنسحب هذه صامتةً ويردّ الأحدث على الرسائل مجتمعةً. وبهذا تُنتج ثلاث
 * رسائل متتالية ردًّا واحدًا.
 *
 * والمفاتيح تُعاد قراءتها هنا لا يُكتفى بفحصها عند الجدولة: قد تُحوَّل المحادثة
 * إلى موظفةٍ أو يُطفأ الوكيل في الثواني الخمس بين الجدولة والتنفيذ — وأسوأ ما
 * يقع أن يتكلّم الوكيل بعد أن أوقفه إنسان.
 */
class DispatchAgentReply implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $generation,
    ) {}

    public function handle(MessageBuffer $buffer): void
    {
        if (! $buffer->isCurrent($this->conversationId, $this->generation)) {
            return; // جاءت رسالةٌ بعدها؛ الردّ لجيلٍ أحدث.
        }

        $conversation = Conversation::with('contact.channel')->find($this->conversationId);

        if ($conversation === null || ! $conversation->agentMayReply()) {
            $buffer->clear($this->conversationId);

            return;
        }

        // TODO(agent-runner): تشغيل الوكيل — يُضاف في كوميت «sales prompt builder
        // and agent runner». حتى ذلك الحين يبقى الاستقبال والتخزين عاملين وحدهما.
        $buffer->clear($this->conversationId);
    }
}
