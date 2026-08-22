<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جداول الصندوق الموحّد.
 *
 * ما تحرسه هذه الاختبارات ليس وجود الأعمدة بل **الضمانات** التي بُنيت من أجلها:
 * استحالة تكرار الرسالة في قاعدة البيانات، وصمت الوكيل ما لم يُفتَح له ثلاثة
 * مفاتيح، وقفل نافذة الأربع والعشرين ساعة.
 */
class InboxSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function channel(array $attributes = []): MessagingChannel
    {
        return MessagingChannel::create($attributes + [
            'provider' => 'whatsapp',
            'name' => 'رقم توفير',
            'external_id' => '1234567890',
            'is_active' => true,
            'ai_enabled' => true,
        ]);
    }

    private function conversation(?MessagingChannel $channel = null, array $attributes = []): Conversation
    {
        $contact = ChannelContact::create([
            'channel_id' => ($channel ?? $this->channel())->id,
            'external_id' => '970599123456',
            'last_inbound_at' => now(),
        ]);

        return Conversation::create($attributes + [
            'channel_contact_id' => $contact->id,
            'status_id' => ConversationStatus::defaultId(),
            'last_message_at' => now(),
        ]);
    }

    /**
     * معرّف الرسالة فريدٌ في قاعدة البيانات لا في الكود.
     *
     * ميتا تُعيد الـwebhook عند أي تأخّر، والحارس البرمجيّ وحده يُنقَض بسباق
     * تنفيذٍ متزامن — فتُخزَّن الرسالة مرّتين ويردّ الوكيل مرّتين على سؤالٍ واحد.
     */
    public function test_a_repeated_message_id_is_impossible(): void
    {
        $conversation = $this->conversation();

        Message::create([
            'conversation_id' => $conversation->id,
            'external_id' => 'wamid.TEST1',
            'direction' => Message::IN,
            'sender_type' => 'customer',
            'body' => 'مرحبا',
        ]);

        $this->expectException(QueryException::class);

        Message::create([
            'conversation_id' => $conversation->id,
            'external_id' => 'wamid.TEST1',
            'direction' => Message::IN,
            'sender_type' => 'customer',
            'body' => 'مرحبا',
        ]);
    }

    /** والرسالة الصادرة تُخزَّن بلا معرّفٍ حتى يعود من المنصّة. */
    public function test_outbound_messages_may_wait_for_their_id(): void
    {
        $conversation = $this->conversation();

        foreach (['أهلًا', 'كيف أساعدك؟'] as $body) {
            Message::create([
                'conversation_id' => $conversation->id,
                'external_id' => null,
                'direction' => Message::OUT,
                'sender_type' => 'ai',
                'body' => $body,
            ]);
        }

        $this->assertSame(2, $conversation->messages()->count());
    }

    // ────────── صمت الوكيل ──────────

    /** ثلاثة مفاتيح يجب أن تُفتح معًا قبل أن ينطق الوكيل. */
    public function test_the_agent_speaks_only_when_every_switch_is_on(): void
    {
        config(['ai_agent.enabled' => true]);

        $this->assertTrue($this->conversation()->agentMayReply());
    }

    /** المفتاح العام يُسكته. */
    public function test_the_global_switch_silences_the_agent(): void
    {
        config(['ai_agent.enabled' => false]);

        $this->assertFalse($this->conversation()->agentMayReply());
    }

    /** ومفتاح القناة يُسكته وحده. */
    public function test_the_channel_switch_silences_the_agent(): void
    {
        config(['ai_agent.enabled' => true]);

        $this->assertFalse($this->conversation($this->channel(['ai_enabled' => false]))->agentMayReply());
    }

    /**
     * والمحوَّلة إلى موظفة لا يعود إليها الوكيل من تلقاء نفسه.
     *
     * وهذا أهمّ صمتٍ في الملف: المحادثة تُحوَّل غالبًا لغضبٍ أو شكوى، وعودةُ
     * الوكيل إليها تُضاعف الغضب.
     */
    public function test_a_handed_off_conversation_stays_silent(): void
    {
        config(['ai_agent.enabled' => true]);

        $conversation = $this->conversation(null, ['ai_mode' => Conversation::AI_HANDED_OFF]);

        $this->assertFalse($conversation->agentMayReply());
    }

    // ────────── نافذة الأربع والعشرين ساعة ──────────

    /** النافذة مفتوحةٌ برسالةٍ واردة حديثة. */
    public function test_the_window_is_open_after_a_recent_inbound(): void
    {
        $contact = $this->conversation()->contact;

        $this->assertTrue($contact->windowOpen());
    }

    /** ومغلقةٌ بعد أربعٍ وعشرين ساعة — وتجاوزها يخصم من تقييم الرقم لا يفشل فحسب. */
    public function test_the_window_closes_after_a_day(): void
    {
        $contact = $this->conversation()->contact;
        $contact->update(['last_inbound_at' => now()->subDay()->subMinute()]);

        $this->assertFalse($contact->fresh()->windowOpen());
    }

    /** ومغلقةٌ لمن لم يراسل قطّ. */
    public function test_the_window_is_closed_without_any_inbound(): void
    {
        $contact = $this->conversation()->contact;
        $contact->update(['last_inbound_at' => null]);

        $this->assertFalse($contact->fresh()->windowOpen());
    }

    // ────────── الأسرار ──────────

    /** بيانات اعتماد القناة لا تُخزَّن نصًّا ظاهرًا. */
    public function test_channel_credentials_are_encrypted_at_rest(): void
    {
        $channel = $this->channel(['credentials' => ['token' => 'EAA-سرّي-جدًّا']]);

        $raw = (string) \DB::table('messaging_channels')->where('id', $channel->id)->value('credentials');

        $this->assertStringNotContainsString('EAA-سرّي-جدًّا', $raw);
        $this->assertSame('EAA-سرّي-جدًّا', $channel->fresh()->credentials['token']);
    }
}
