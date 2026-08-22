<?php

namespace Tests\Feature\Messaging;

use App\Jobs\DispatchAgentReply;
use App\Modules\AiAgent\Support\MessageBuffer;
use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * استقبال رسائل واتساب الواردة.
 *
 * ما تحرسه هذه الاختبارات معايير القبول نفسها: رفض التوقيع الخاطئ، واستحالة
 * التكرار، وردٌّ واحد لثلاث رسائل، وبقاء الاستقبال عاملًا والوكيل مُطفأ.
 */
class InboundWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-اختبار';

    private const PHONE_ID = '1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config([
            'messaging.whatsapp.app_secret' => self::SECRET,
            'ai_agent.enabled' => true,
        ]);

        MessagingChannel::create([
            'provider' => 'whatsapp',
            'name' => 'رقم توفير',
            'external_id' => self::PHONE_ID,
            'is_active' => true,
            'ai_enabled' => true,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $messages */
    private function payload(array $messages, string $phoneId = self::PHONE_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneId],
                        'contacts' => [['wa_id' => '970599123456', 'profile' => ['name' => 'أبو محمد']]],
                        'messages' => $messages,
                    ],
                ]],
            ]],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function hook(array $payload, bool $sign = true)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = $sign
            ? ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET)]
            : [];

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], $this->transform($headers), $body);
    }

    /** @param  array<string, string>  $headers */
    private function transform(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($key))] = $value;
        }

        return $server;
    }

    /** @return array<string, mixed> */
    private function text(string $id, string $body): array
    {
        return [
            'id' => $id,
            'from' => '970599123456',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => $body],
        ];
    }

    // ────────── التوقيع ──────────

    /** التوقيع الخاطئ يُرفض — النقطة عامّة، وبلا تحقّقٍ يكتب فيها أيُّ أحد. */
    public function test_a_bad_signature_is_rejected(): void
    {
        $this->hook($this->payload([$this->text('wamid.A', 'مرحبا')]), sign: false)
            ->assertStatus(403);

        $this->assertSame(0, Message::count());
    }

    // ────────── التخزين ──────────

    /** الرسالة الواردة تُخزَّن وتفتح محادثةً وتفتح نافذة الأربع والعشرين ساعة. */
    public function test_an_inbound_message_opens_a_conversation(): void
    {
        $this->hook($this->payload([$this->text('wamid.A', 'بدي أسأل عن المكنسة')]))->assertOk();

        $message = Message::firstOrFail();

        $this->assertSame('بدي أسأل عن المكنسة', $message->body);
        $this->assertSame(Message::IN, $message->direction);
        $this->assertSame('customer', $message->sender_type);

        $contact = ChannelContact::firstOrFail();
        $this->assertSame('أبو محمد', $contact->display_name);
        $this->assertTrue($contact->windowOpen());
        $this->assertSame(1, Conversation::count());
    }

    /**
     * والوسائط لا تُفقد سؤالها.
     *
     * «هذا بكم؟» تحت صورة هي السؤال نفسه، وإهمال التعليق يُبقي صورةً بلا معنى.
     */
    public function test_a_media_caption_is_kept_as_the_body(): void
    {
        $this->hook($this->payload([[
            'id' => 'wamid.IMG',
            'from' => '970599123456',
            'type' => 'image',
            'image' => ['id' => 'media-1', 'caption' => 'هذا بكم؟'],
        ]]))->assertOk();

        $message = Message::firstOrFail();

        $this->assertSame('image', $message->type);
        $this->assertSame('هذا بكم؟', $message->body);
    }

    /** ورسالةٌ لرقمٍ غير مربوط تُتجاهَل ولا تكسر الاستقبال. */
    public function test_an_unknown_phone_number_is_ignored(): void
    {
        $this->hook($this->payload([$this->text('wamid.A', 'مرحبا')], phoneId: '999'))->assertOk();

        $this->assertSame(0, Message::count());
    }

    // ────────── منع التكرار ──────────

    /**
     * إعادة الإرسال من ميتا لا تُنشئ صفًّا مكرّرًا ولا تُرجع خطأً.
     *
     * والخطأ هنا أسوأ من التكرار: ميتا تُعيد المحاولة على كل استجابةٍ غير
     * ناجحة، فيصير الخطأ حلقةً لا تنتهي.
     */
    public function test_a_replayed_webhook_stores_nothing_twice(): void
    {
        $payload = $this->payload([$this->text('wamid.SAME', 'مرحبا')]);

        $this->hook($payload)->assertOk();
        $this->hook($payload)->assertOk();

        $this->assertSame(1, Message::count());
    }

    // ────────── التجميع ──────────

    /** ثلاث رسائل خلال ثانيتين تنتج ردًّا واحدًا. */
    public function test_three_messages_produce_one_reply(): void
    {
        Queue::fake();

        $this->hook($this->payload([
            $this->text('wamid.1', 'مرحبا'),
            $this->text('wamid.2', 'بدي أسأل'),
            $this->text('wamid.3', 'هذا بكم؟'),
        ]))->assertOk();

        $this->assertSame(3, Message::count());

        // ثلاث مهامّ مجدولة، لكن أحدثَ جيلٍ وحده يعمل والبقيّة تنسحب صامتة.
        $current = collect(Queue::pushedJobs())
            ->flatten(1)
            ->pluck('job')
            ->filter(fn ($job) => $job instanceof DispatchAgentReply)
            ->filter(fn (DispatchAgentReply $job) => app(MessageBuffer::class)
                ->isCurrent($job->conversationId, $job->generation));

        $this->assertCount(1, $current);
    }

    // ────────── مفتاح الإطفاء ──────────

    /**
     * الوكيل مُطفأ ⇒ لا ردّ، **والاستقبال والتخزين يعملان**.
     *
     * وهذا معيار قبولٍ صريح: إطفاء الوكيل لا يجوز أن يُفقد رسالة زبون.
     */
    public function test_a_disabled_agent_still_receives_and_stores(): void
    {
        Queue::fake();
        config(['ai_agent.enabled' => false]);

        $this->hook($this->payload([$this->text('wamid.OFF', 'مرحبا')]))->assertOk();

        $this->assertSame(1, Message::count());
        Queue::assertNotPushed(DispatchAgentReply::class);
    }

    /** ومحادثةٌ محوَّلة لموظفة لا تُجدول للوكيل أصلًا. */
    public function test_a_handed_off_conversation_is_never_scheduled(): void
    {
        Queue::fake();

        $this->hook($this->payload([$this->text('wamid.1', 'مرحبا')]))->assertOk();
        Conversation::query()->update(['ai_mode' => Conversation::AI_HANDED_OFF]);

        $this->hook($this->payload([$this->text('wamid.2', 'في حدا؟')]))->assertOk();

        $this->assertSame(2, Message::count());
        Queue::assertPushed(DispatchAgentReply::class, 1); // الأولى فقط.
    }

    /** والمحادثة تُستأنف ولا تبدأ من الصفر مع كل رسالة. */
    public function test_a_returning_customer_continues_the_same_conversation(): void
    {
        $this->hook($this->payload([$this->text('wamid.1', 'مرحبا')]))->assertOk();
        $this->hook($this->payload([$this->text('wamid.2', 'رجعت')]))->assertOk();

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, Conversation::firstOrFail()->messages()->count());
    }
}
