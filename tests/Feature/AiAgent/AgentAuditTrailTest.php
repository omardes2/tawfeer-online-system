<?php

namespace Tests\Feature\AiAgent;

use App\Models\User;
use App\Modules\AiAgent\Models\AgentRun;
use App\Modules\AiAgent\Models\AgentToolCall;
use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\MessagingChannel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * سجلّ تدقيق الوكيل والمعرفة البيعية.
 *
 * وكيلٌ يحادث الزبائن باسم الشركة يجب أن يُسأل: ماذا قال؟ ولماذا؟ وبكم؟
 * وسجلٌّ يُعدَّل لا يُجيب — لأن أوّل ما يُغرى بمحوه هو الاستدعاء الذي أخطأ.
 */
class AgentAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
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

    private function agentRun(array $attributes = []): AgentRun
    {
        return AgentRun::create($attributes + [
            'conversation_id' => $this->conversation()->id,
            'model' => 'claude-sonnet-5',
            'input_tokens' => 1200,
            'output_tokens' => 180,
            'cost' => '0.0132',
            'latency_ms' => 940,
            'outcome' => 'replied',
        ]);
    }

    // ────────── لا يُعدَّل ولا يُحذف ──────────

    /** سجلّ الاستدعاء يُكتب ولا يُعدَّل. */
    public function test_a_run_can_never_be_edited(): void
    {
        $run = $this->agentRun();

        $this->expectException(RuntimeException::class);

        $run->update(['outcome' => 'silent']);
    }

    /** ولا يُحذف — وأوّل ما يُغرى بمحوه هو ما أخطأ. */
    public function test_a_run_can_never_be_deleted(): void
    {
        $run = $this->agentRun();

        $this->expectException(RuntimeException::class);

        $run->delete();
    }

    /** وكذلك سجلّ الأدوات. */
    public function test_a_tool_call_can_never_be_edited(): void
    {
        $call = AgentToolCall::create([
            'agent_run_id' => $this->agentRun()->id,
            'tool_name' => 'get_price',
            'arguments' => ['variant_id' => 5, 'qty' => 1],
            'result' => ['unit_price' => '100.00'],
            'status' => 'ok',
            'duration_ms' => 12,
        ]);

        $this->expectException(RuntimeException::class);

        $call->update(['result' => ['unit_price' => '1.00']]);
    }

    // ────────── الأرقام ──────────

    /**
     * التكلفة تحفظ كسورها.
     *
     * استدعاءٌ بـ0.0132$ يصير صفرًا بدقّة قرشين، فتُقرأ ألف محادثةٍ بلا تكلفة —
     * ولهذا وحده تُستثنى هذه من `decimal(15,2)`.
     */
    public function test_the_cost_keeps_its_fractions(): void
    {
        $this->assertSame('0.0132', (string) $this->agentRun()->fresh()->cost);
    }

    /** والتوكنز تُحفظ كما هي لقياس الاستهلاك. */
    public function test_tokens_are_recorded_for_every_call(): void
    {
        $run = $this->agentRun()->fresh();

        $this->assertSame(1200, $run->input_tokens);
        $this->assertSame(180, $run->output_tokens);
    }

    // ────────── المعرفة البيعية ──────────

    /** صنفٌ بلا معرفةٍ جاهزة لا يبيعه الوكيل. */
    public function test_a_product_without_ready_knowledge_is_not_sellable_by_the_agent(): void
    {
        $product = Product::factory()->create();

        ProductKnowledge::create([
            'product_id' => $product->id,
            'selling_points' => ['يشتغل على البطارية'],
            'is_ready' => false,
        ]);

        $this->assertSame(0, ProductKnowledge::ready()->count());
    }

    /** والجاهز يظهر بمحتواه المهيكل. */
    public function test_ready_knowledge_keeps_its_structure(): void
    {
        $product = Product::factory()->create();

        ProductKnowledge::create([
            'product_id' => $product->id,
            'selling_points' => ['يوفّر وقت التنظيف'],
            'objections' => [['objection' => 'غالي', 'response' => 'بيوفّر أجرة عاملة بشهر']],
            'faq' => [['question' => 'بشتغل عالكهربا؟', 'answer' => 'لأ، بطارية']],
            'is_ready' => true,
            'updated_by' => User::where('email', 'admin@tawfeer.online')->value('id'),
        ]);

        $knowledge = ProductKnowledge::ready()->firstOrFail();

        $this->assertSame('غالي', $knowledge->objections[0]['objection']);
        $this->assertSame('بشتغل عالكهربا؟', $knowledge->faq[0]['question']);
    }

    // ────────── الصلاحيات ──────────

    /** إيقاف الوكيل قرارٌ إداريّ لا عملُ خدمةٍ يومي. */
    public function test_toggling_the_agent_is_an_admin_decision(): void
    {
        $sales = User::factory()->create(['branch_id' => Branch::default()->id]);
        $sales->assignRole('sales');

        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        // موظف المبيعات يردّ ويحوّل، ولا يُشغّل الوكيل ولا يوقفه.
        $this->assertTrue($sales->can('inbox.reply'));
        $this->assertTrue($sales->can('ai_agent.handoff'));
        $this->assertFalse($sales->can('ai_agent.toggle'));
        $this->assertFalse($sales->can('ai_agent.knowledge.manage'));

        $this->assertTrue($admin->can('ai_agent.toggle'));
    }
}
