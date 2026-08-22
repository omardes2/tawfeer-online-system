<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * استقبال رسائل واتساب الواردة وتخزينها في الصندوق الموحّد.
 *
 * ثلاثة قرارات تحكم هذا الملف:
 *
 * 1. **التكرار يُبتلع صامتًا.** ميتا تُعيد إرسال الـwebhook عند أي تأخّرٍ في
 *    الردّ، فتصل الرسالة نفسها مرّتين وثلاثًا. الفهرس الفريد على `external_id`
 *    يمنع الصفّ الثاني، ويُلتقط الاصطدام هنا فيُرجَع «مكرّرة» بلا خطأ — لأن
 *    الخطأ يجعل ميتا تُعيد المحاولة من جديدٍ في حلقةٍ لا تنتهي.
 *
 * 2. **لا يُنشأ عميل.** المُراسِل يُسجَّل في `channel_contacts` وحدها؛ ومن يسأل
 *    عن منتجٍ ليس عميلًا بعد. الربط بـ`customers` يقع عند أول طلبٍ لا قبله.
 *
 * 3. **`last_inbound_at` يُحدَّث مع كل رسالةٍ واردة.** عليه وحده تُقاس نافذة
 *    الأربع والعشرين ساعة التي تسمح بها ميتا للنصّ الحرّ — ونسيانُ تحديثه يعني
 *    رفضَ كل ردٍّ بعد يوم.
 */
class InboundMessageService
{
    /** أنواع الرسائل المدعومة؛ وما عداها يُخزَّن كنصٍّ بحمولته الخام. */
    private const TYPES = ['text', 'image', 'video', 'document', 'audio'];

    /**
     * تطبيق حمولة الـwebhook.
     *
     * @param  array<string, mixed>  $payload
     * @return array{stored: int, duplicates: int, conversations: array<int, int>}
     */
    public function apply(array $payload): array
    {
        $summary = ['stored' => 0, 'duplicates' => 0, 'conversations' => []];

        foreach ($this->rows($payload) as [$phoneNumberId, $contacts, $message]) {
            $channel = $this->channel($phoneNumberId);

            if ($channel === null) {
                Log::warning('whatsapp.inbound.unknown_channel', ['phone_number_id' => $phoneNumberId]);

                continue;
            }

            $stored = $this->store($channel, $contacts, $message);

            if ($stored === null) {
                $summary['duplicates']++;

                continue;
            }

            $summary['stored']++;
            $summary['conversations'][] = $stored->conversation_id;
        }

        $summary['conversations'] = array_values(array_unique($summary['conversations']));

        return $summary;
    }

    /**
     * تخزين رسالةٍ واحدة. يُرجع `null` إن كانت مكرّرة.
     *
     * @param  array<string, mixed>  $contacts  ملفّات المُراسِلين من الحمولة
     * @param  array<string, mixed>  $message
     */
    private function store(MessagingChannel $channel, array $contacts, array $message): ?Message
    {
        $from = (string) ($message['from'] ?? '');
        $externalId = (string) ($message['id'] ?? '');

        if ($from === '' || $externalId === '') {
            return null;
        }

        try {
            return DB::transaction(function () use ($channel, $contacts, $message, $from, $externalId) {
                $contact = $this->contact($channel, $from, $contacts);
                $conversation = $this->conversation($contact);

                $stored = Message::create([
                    'conversation_id' => $conversation->id,
                    'external_id' => $externalId,
                    'direction' => Message::IN,
                    'sender_type' => 'customer',
                    'type' => $this->type($message),
                    'body' => $this->body($message),
                    'payload' => $message,
                    'delivery_status' => 'delivered',
                    'sent_at' => isset($message['timestamp'])
                        ? now()->setTimestamp((int) $message['timestamp'])
                        : now(),
                ]);

                // نافذة الأربع والعشرين ساعة تُفتح من هنا، ولا شيء غيرها يفتحها.
                $contact->update(['last_inbound_at' => $stored->sent_at]);
                $conversation->update(['last_message_at' => $stored->sent_at]);

                return $stored;
            });
        } catch (QueryException $e) {
            // اصطدام الفهرس الفريد = رسالةٌ وصلت مرّتين. تُبتلع صامتةً: رفعُ
            // الخطأ يجعل ميتا تُعيد الإرسال من جديدٍ في حلقةٍ لا تنتهي.
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $contacts */
    private function contact(MessagingChannel $channel, string $from, array $contacts): ChannelContact
    {
        $name = null;
        foreach ($contacts as $profile) {
            if ((string) ($profile['wa_id'] ?? '') === $from) {
                $name = $profile['profile']['name'] ?? null;
                break;
            }
        }

        $contact = ChannelContact::firstOrCreate(
            ['channel_id' => $channel->id, 'external_id' => $from],
            ['display_name' => $name],
        );

        // الاسم يُحدَّث إن تغيّر على ملفّه، ولا يُمحى إن غاب عن هذه الحمولة.
        if ($name !== null && $name !== $contact->display_name) {
            $contact->update(['display_name' => $name]);
        }

        return $contact;
    }

    /**
     * محادثةٌ مفتوحة أو جديدة.
     *
     * المحادثة تُستأنف ما لم تُغلَق: الزبون الذي يعود بعد أسبوعٍ يكمل حديثه ولا
     * يبدأ من الصفر، فيرى الموظفُ سياقه كاملًا.
     */
    private function conversation(ChannelContact $contact): Conversation
    {
        $open = Conversation::query()
            ->where('channel_contact_id', $contact->id)
            ->whereHas('status', fn ($q) => $q->where('is_final', false))
            ->latest('id')
            ->first();

        return $open ?? Conversation::create([
            'channel_contact_id' => $contact->id,
            'status_id' => ConversationStatus::defaultId(),
            'last_message_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $message */
    private function type(array $message): string
    {
        $type = (string) ($message['type'] ?? 'text');

        return in_array($type, self::TYPES, true) ? $type : 'text';
    }

    /**
     * نصّ الرسالة مهما كان نوعها.
     *
     * الوسائط تحمل تعليقًا (`caption`) وهو غالبًا السؤال نفسه — «هذا بكم؟» تحت
     * صورة. وإهماله يُفقد الوكيل السؤال ويُبقي صورةً بلا معنى.
     *
     * @param  array<string, mixed>  $message
     */
    private function body(array $message): ?string
    {
        $type = (string) ($message['type'] ?? 'text');

        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            default => $message[$type]['caption'] ?? null,
        };
    }

    /**
     * صفوف الرسائل مع معرّف الرقم وملفّات المُراسِلين.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    private function rows(array $payload): array
    {
        $rows = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $contacts = (array) ($value['contacts'] ?? []);

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (is_array($message)) {
                        $rows[] = [$phoneNumberId, $contacts, $message];
                    }
                }
            }
        }

        return $rows;
    }

    private function channel(string $phoneNumberId): ?MessagingChannel
    {
        if ($phoneNumberId === '') {
            return null;
        }

        return MessagingChannel::query()->active()
            ->where('provider', 'whatsapp')
            ->where('external_id', $phoneNumberId)
            ->first();
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23000 لـMySQL و SQLite معًا عند خرق قيد الفرادة.
        return (string) $e->getCode() === '23000';
    }
}
