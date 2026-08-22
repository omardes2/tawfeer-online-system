<?php

namespace Tests\Feature\AiAgent;

use App\Modules\AiAgent\Models\AgentRun;
use App\Modules\AiAgent\Services\RunSalesAgent;
use App\Modules\AiAgent\Services\SalesAgentPrompt;
use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مشغّل الوكيل — من رسالة الزبون إلى ردٍّ مُرسَل.
 *
 * الفحص يقع على **ما يصل الزبون وما يُسجَّل**، لا على شكل الطلب المُرسل إلى
 * النموذج: الأول عقدٌ مع المستخدم، والثاني تفصيلٌ يتغيّر.
 */
class SalesAgentRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config([
            'ai_agent.enabled' => true,
            'ai_agent.api_key' => 'test-key',
            'ai_agent.model' => 'claude-sonnet-5',
        ]);
    }

    private function conversation(): Conversation
    {
        $channel = MessagingChannel::create([
            'provider' => 'whatsapp', 'name' => 'رقم', 'external_id' => '1',
            'is_active' => true, 'ai_enabled' => true,
        ]);

        $contact = ChannelContact::create([
            'channel_id' => $channel->id, 'external_id' => '970599123456', 'last_inbound_at' => now(),
        ]);

        return Conversation::create([
            'channel_contact_id' => $contact->id,
            'status_id' => ConversationStatus::defaultId(),
            'last_message_at' => now(),
        ]);
    }

    private function ask(Conversation $conversation, string $body, string $direction = Message::IN): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'external_id' => 'wamid.'.uniqid(),
            'direction' => $direction,
            'sender_type' => $direction === Message::IN ? 'customer' : 'ai',
            'type' => 'text',
            'body' => $body,
            'sent_at' => now(),
        ]);
    }

    /** ردٌّ نصّي من النموذج ⇒ رسالة صادرة وسجلٌّ بالتوكنز والتكلفة. */
    public function test_a_text_answer_reaches_the_customer_and_the_ledger(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'مرحبا، بدي أسأل عن المكنسة');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'أهلًا فيك! أكيد، بحكيلك عنها.']],
                'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000],
            ]),
        ]);

        $run = app(RunSalesAgent::class)->handle($conversation, [1]);

        $this->assertSame('replied', $run->outcome);
        $this->assertSame('18.0000', $run->cost);   // مليون دخل ($3) + مليون خرج ($15)

        $reply = Message::where('direction', Message::OUT)->latest('id')->first();
        $this->assertNotNull($reply);
        $this->assertSame('أهلًا فيك! أكيد، بحكيلك عنها.', $reply->body);
    }

    /** فشل النداء ⇒ تحويلٌ واعتذار، لا صمت. */
    public function test_a_failed_call_hands_the_conversation_over(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'بكم؟');

        Http::fake(['api.anthropic.com/*' => Http::response('boom', 500)]);

        $run = app(RunSalesAgent::class)->handle($conversation);

        $this->assertSame('failed', $run->outcome);
        $this->assertNotNull($run->error);
        $this->assertSame(Conversation::AI_HANDED_OFF, $conversation->fresh()->ai_mode);
        $this->assertSame('agent_error', $conversation->fresh()->handoff_reason);
    }

    /**
     * ردٌّ بلا نصّ ⇒ تحويل.
     *
     * ليس خطأً تقنيًّا، لكنه من زاوية الزبون صمتٌ تامّ بعد سؤاله.
     */
    public function test_an_empty_answer_hands_over_instead_of_going_silent(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'موجود؟');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
            ]),
        ]);

        $run = app(RunSalesAgent::class)->handle($conversation);

        $this->assertSame('escalated', $run->outcome);
        $this->assertSame('empty_reply', $conversation->fresh()->handoff_reason);
    }

    /**
     * دورةٌ لا تنتهي ⇒ تُقطع عند السقف وتُحوَّل.
     *
     * نموذجٌ لا يجد جوابًا يعيد الاستدعاء بلا نهاية: مالٌ يُنفق وزبونٌ ينتظر
     * ولا شيء يصل.
     */
    public function test_an_endless_tool_loop_is_cut_at_the_ceiling(): void
    {
        config(['ai_agent.max_tool_calls' => 2]);

        $conversation = $this->conversation();
        $this->ask($conversation, 'شو الأسعار؟');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'tool_use', 'id' => 'tu_1',
                    'name' => 'search_products', 'input' => ['query' => 'مكنسة'],
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ]),
        ]);

        $run = app(RunSalesAgent::class)->handle($conversation);

        $this->assertSame('escalated', $run->outcome);
        $this->assertSame('tool_limit', $conversation->fresh()->handoff_reason);
        $this->assertLessThanOrEqual(3, $run->toolCalls()->count());
    }

    /** واستدعاء الأداة يُسجَّل ويعود إلى النموذج فيردّ. */
    public function test_a_tool_result_comes_back_and_the_agent_answers(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'في مكنسة؟');

        Http::fakeSequence()
            ->push([
                'content' => [[
                    'type' => 'tool_use', 'id' => 'tu_1',
                    'name' => 'search_products', 'input' => ['query' => 'مكنسة'],
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ])
            ->push([
                'content' => [['type' => 'text', 'text' => 'ما لقيت الصنف، بحوّلك لموظفة.']],
                'usage' => ['input_tokens' => 150, 'output_tokens' => 30],
            ]);

        $run = app(RunSalesAgent::class)->handle($conversation);

        $this->assertSame('replied', $run->outcome);
        $this->assertSame(250, $run->input_tokens);   // النداءان مجموعان
        $this->assertSame(50, $run->output_tokens);
        $this->assertSame(1, $run->toolCalls()->count());
        $this->assertSame('search_products', $run->toolCalls()->first()->tool_name);
    }

    /** ومحادثةٌ بلا رسالة زبون لا تستهلك نداءً أصلًا. */
    public function test_a_conversation_with_no_customer_message_never_calls_the_model(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'أهلًا بك في متجرنا', Message::OUT);

        Http::fake();

        $run = app(RunSalesAgent::class)->handle($conversation);

        $this->assertSame('silent', $run->outcome);
        Http::assertNothingSent();
    }

    /** كل دورة تترك صفًّا — حتى الفاشلة. */
    public function test_every_run_leaves_a_row(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'مرحبا');

        Http::fake(['api.anthropic.com/*' => Http::response('nope', 401)]);

        app(RunSalesAgent::class)->handle($conversation);

        $this->assertSame(1, AgentRun::where('conversation_id', $conversation->id)->count());
    }

    // ────────── التعليمات والتاريخ ──────────

    /**
     * التعليمات لا تحمل سعرًا ولا صنفًا.
     *
     * المبدأ الأول لا يُصان بالأمر وحده: نموذجٌ أُعطي أسعارًا في تعليماته
     * سيذكرها من ذاكرته ولن يستدعي أداة.
     */
    public function test_the_system_prompt_carries_no_catalogue_and_no_prices(): void
    {
        $system = app(SalesAgentPrompt::class)->system();

        $this->assertStringNotContainsString('₪', $system);
        $this->assertMatchesRegularExpression('/لا تذكر سعرًا/u', $system);
        $this->assertSame(0, preg_match('/\d+\.\d{2}/', $system), 'التعليمات تحمل رقمًا يشبه السعر');
    }

    /** والتاريخ يبدأ من رسالة زبون — الواجهة ترفض ما عداه. */
    public function test_the_history_starts_at_a_customer_message(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'أهلًا بك', Message::OUT);
        $this->ask($conversation, 'مرحبا');

        $history = app(SalesAgentPrompt::class)->history($conversation);

        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('مرحبا', $history[0]['content']);
    }

    /** والمتتاليات من الدور نفسه تُدمج في نوبةٍ واحدة. */
    public function test_consecutive_messages_from_the_same_side_merge(): void
    {
        $conversation = $this->conversation();
        $this->ask($conversation, 'مرحبا');
        $this->ask($conversation, 'بدي أسأل');
        $this->ask($conversation, 'هذا بكم؟');

        $history = app(SalesAgentPrompt::class)->history($conversation);

        $this->assertCount(1, $history);
        $this->assertSame("مرحبا\nبدي أسأل\nهذا بكم؟", $history[0]['content']);
    }
}
