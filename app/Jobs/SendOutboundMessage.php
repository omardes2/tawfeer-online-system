<?php

namespace App\Jobs;

use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Services\OutboundMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * إرسال رسالةٍ صادرة خارج دورة الطلب.
 *
 * **بلا إعادة محاولةٍ تلقائية** (`$tries = 1`): نداءٌ نجح ثم انقطع الاتصال قبل
 * أن تعود استجابته يُنفَّذ مرّتين إن أُعيد، فتصل الرسالة مرّتين لشخصٍ واحد —
 * وهو بالضبط ما يدفعه إلى الحجب. الفشل يُسجَّل على الرسالة ويُقرّره إنسان.
 */
class SendOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $body,
        public readonly string $senderType = 'ai',
    ) {}

    public function handle(OutboundMessageService $outbound): void
    {
        $conversation = Conversation::with('contact')->find($this->conversationId);

        if ($conversation !== null) {
            $outbound->sendText($conversation, $this->body, $this->senderType);
        }
    }
}
