<?php

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Support\Integrations\Messaging\MessagingManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إرسال رسالةٍ صادرة في محادثة.
 *
 * ثلاثة قرارات:
 *
 * 1. **نافذة الأربع والعشرين ساعة تُفحص قبل كل إرسالٍ حرّ.** قاعدة ميتا: لا نصَّ
 *    حرًّا بعد يومٍ من آخر رسالةٍ واردة — قوالبُ معتمَدة فقط. وتجاوزها لا يُنتج
 *    خطأً فحسب بل **يخصم من تقييم الرقم**، وتراكمُه يوصل إلى حظره. فالرفض هنا
 *    حمايةٌ للرقم لا تشدّدٌ في القواعد.
 *
 * 2. **الرسالة تُخزَّن قبل الإرسال لا بعده.** الإرسال قد ينجح ثم ينقطع الاتصال
 *    قبل أن يعود المعرّف؛ فلو خُزّنت بعده لضاعت رسالةٌ وصلت الزبون فعلًا،
 *    ولأعيد إرسالها. تُخزَّن أولًا بحالة `queued` ثم تُحدَّث بنتيجتها.
 *
 * 3. **الفشل يُسجَّل ولا يُبتلع.** الموظفة ترى في الصندوق أن الرسالة لم تصل
 *    وسببَها، بدل أن تظنّ أنها ردّت وهي لم تفعل.
 */
class OutboundMessageService
{
    public function __construct(private readonly MessagingManager $messaging) {}

    /**
     * إرسال نصٍّ حرّ.
     *
     * @param  string  $senderType  ai | agent | system
     */
    public function sendText(
        Conversation $conversation,
        string $body,
        string $senderType = 'ai',
        ?User $sender = null,
    ): Message {
        return $this->send($conversation, $body, $senderType, $sender, []);
    }

    /**
     * إرسال قالبٍ معتمَد — الطريق الوحيد خارج نافذة الأربع والعشرين ساعة.
     *
     * @param  array<int, string>  $variables
     */
    public function sendTemplate(
        Conversation $conversation,
        string $template,
        array $variables = [],
        string $senderType = 'agent',
        ?User $sender = null,
    ): Message {
        return $this->send($conversation, '', $senderType, $sender, [
            'template' => $template,
            'variables' => $variables,
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    private function send(
        Conversation $conversation,
        string $body,
        string $senderType,
        ?User $sender,
        array $meta,
    ): Message {
        $conversation->loadMissing('contact');
        $contact = $conversation->contact;
        $isTemplate = filled($meta['template'] ?? null);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::OUT,
            'sender_type' => $senderType,
            'sender_user_id' => $sender?->id,
            'type' => $isTemplate ? 'template' : 'text',
            'body' => $body,
            'delivery_status' => 'queued',
        ]);

        // النصّ الحرّ خارج النافذة يُرفض ولا يُحاوَل: المحاولة تُخصم من تقييم
        // الرقم، والقالب وحده مسموحٌ هناك.
        if (! $isTemplate && ! ($contact?->windowOpen() ?? false)) {
            $message->update([
                'delivery_status' => 'failed',
                'failed_reason' => __('خارج نافذة الأربع والعشرين ساعة — يلزم قالب معتمَد.'),
            ]);

            Log::warning('whatsapp.outbound.window_closed', [
                'conversation' => $conversation->id,
                'last_inbound_at' => $contact?->last_inbound_at?->toDateTimeString(),
            ]);

            return $message->fresh();
        }

        try {
            $result = $this->messaging->for('whatsapp')->send(
                'whatsapp',
                (string) $contact?->external_id,
                $body,
                $meta,
            );

            $message->update([
                'external_id' => $result['reference'] ?? null,
                'delivery_status' => $result['status'] === 'sent' ? 'sent' : $result['status'],
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            $message->update([
                'delivery_status' => 'failed',
                'failed_reason' => mb_substr($e->getMessage(), 0, 255),
            ]);

            Log::warning('whatsapp.outbound.failed', [
                'conversation' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        $conversation->update(['last_message_at' => now()]);

        return $message->fresh();
    }
}
