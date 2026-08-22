<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Services\OutboundMessageService;
use App\Support\Integrations\Messaging\FakeMessagingProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإرسال الصادر ونافذة الأربع والعشرين ساعة.
 *
 * أثقل ما يُحرَس هنا ليس نجاح الإرسال بل **رفضه في محلّه**: النصّ الحرّ خارج
 * النافذة تخصم محاولتُه من تقييم الرقم، وتراكمُها يوصل إلى حظره — فالرفض
 * حمايةٌ للرقم لا تشدّدٌ في القواعد.
 */
class OutboundWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private OutboundMessageService $outbound;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['messaging.channels.whatsapp' => 'fake']);
        FakeMessagingProvider::reset();
        $this->outbound = app(OutboundMessageService::class);
    }

    private function conversation(?string $lastInboundAt = 'now'): Conversation
    {
        $channel = MessagingChannel::create([
            'provider' => 'whatsapp',
            'name' => 'رقم توفير',
            'external_id' => '1234567890',
            'is_active' => true,
            'ai_enabled' => true,
        ]);

        $contact = ChannelContact::create([
            'channel_id' => $channel->id,
            'external_id' => '970599123456',
            'last_inbound_at' => $lastInboundAt === 'now' ? now() : $lastInboundAt,
        ]);

        return Conversation::create([
            'channel_contact_id' => $contact->id,
            'status_id' => ConversationStatus::defaultId(),
            'last_message_at' => now(),
        ]);
    }

    // ────────── داخل النافذة ──────────

    /** النصّ الحرّ يُرسَل داخل النافذة ويُحفظ معرّفه. */
    public function test_free_text_is_sent_inside_the_window(): void
    {
        $message = $this->outbound->sendText($this->conversation(), 'أهلًا فيك');

        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame(Message::OUT, $message->direction);
        $this->assertSame('ai', $message->sender_type);
        $this->assertCount(1, FakeMessagingProvider::$sent);
    }

    // ────────── خارج النافذة ──────────

    /**
     * وخارجها يُرفض ولا يُحاوَل أصلًا.
     *
     * وهذا معيار قبولٍ صريح: المحاولة نفسها ضرر، فلا يكفي أن تفشل — يجب ألّا
     * تقع.
     */
    public function test_free_text_is_refused_outside_the_window(): void
    {
        $message = $this->outbound->sendText(
            $this->conversation(now()->subDay()->subMinute()->toDateTimeString()),
            'أهلًا فيك',
        );

        $this->assertSame('failed', $message->delivery_status);
        $this->assertStringContainsString('الأربع والعشرين', (string) $message->failed_reason);
        $this->assertCount(0, FakeMessagingProvider::$sent);
    }

    /** ومن لم يراسل قطّ لا تُفتح له نافذة. */
    public function test_free_text_is_refused_without_any_inbound(): void
    {
        $message = $this->outbound->sendText($this->conversation(null), 'أهلًا');

        $this->assertSame('failed', $message->delivery_status);
        $this->assertCount(0, FakeMessagingProvider::$sent);
    }

    /** والقالب المعتمَد يمرّ خارج النافذة — وهو الطريق الوحيد هناك. */
    public function test_a_template_passes_outside_the_window(): void
    {
        $message = $this->outbound->sendTemplate(
            $this->conversation(now()->subDays(3)->toDateTimeString()),
            'reopen_conversation',
            ['أبو محمد'],
        );

        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame('template', $message->type);
        $this->assertCount(1, FakeMessagingProvider::$sent);
    }

    // ────────── الأثر على الصندوق ──────────

    /**
     * والرسالة تُخزَّن حتى حين يُرفض إرسالها.
     *
     * فترى الموظفة في الصندوق أن الردّ لم يصل وسببَه، بدل أن تظنّ أنها ردّت
     * وهي لم تفعل.
     */
    public function test_a_refused_message_still_appears_in_the_thread(): void
    {
        $conversation = $this->conversation(null);

        $this->outbound->sendText($conversation, 'أهلًا');

        $this->assertSame(1, $conversation->messages()->count());
    }

    /** والإرسال يرفع وقت آخر رسالةٍ فتتصدّر المحادثة الصندوق. */
    public function test_sending_bumps_the_conversation(): void
    {
        $conversation = $this->conversation();
        $conversation->update(['last_message_at' => now()->subHour()]);

        $this->outbound->sendText($conversation, 'أهلًا');

        $this->assertTrue($conversation->fresh()->last_message_at->greaterThan(now()->subMinute()));
    }
}
