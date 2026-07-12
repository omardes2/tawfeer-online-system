<?php

namespace App\Modules\Marketing\Jobs;

use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Services\MessageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * إرسال رسالة حملة عبر الطابور (Phase 6 / إنتاج).
 *
 * ينقل الإرسال الخارجي (واتساب/بريد/SMS) خارج مسار الطلب/الأمر. **آمن لإعادة المحاولة
 * وidempotent**: الرسالة مُنشأة مسبقًا بمفتاح idempotency فريد؛ إن كانت قد أُرسلت فعلًا
 * (status=sent) يتوقّف دون تكرار. كل الحوكمة (موافقة/حجب/هدوء/تكرار) طُبّقت قبل الجدولة.
 */
class SendCampaignMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** تراجع تصاعدي بين المحاولات (ثوانٍ). */
    public array $backoff = [10, 30, 60];

    public function __construct(public int $messageId) {}

    public function handle(MessageDispatcher $dispatcher): void
    {
        $message = CampaignMessage::find($this->messageId);
        if (! $message || $message->status === 'sent') {
            return; // أُرسلت أو حُذفت — لا تكرار.
        }

        $dispatcher->send($message);
    }

    /** مفتاح فريد للرسالة — يساعد المراقبة وتفادي التكرار على مستوى الطابور. */
    public function uniqueId(): string
    {
        return 'campaign-message:'.$this->messageId;
    }
}
