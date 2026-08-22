<?php

namespace Tests\Feature\AiAgent;

use App\Models\User;
use App\Modules\AiAgent\Support\MessageBuffer;
use App\Modules\AiAgent\Tools\EscalateToHumanTool;
use App\Modules\AiAgent\Tools\ToolRegistry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\MessagingChannel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * التحويل إلى إنسان، ومفاتيح إسكات الوكيل.
 *
 * وكيلٌ لا يعرف متى يصمت أخطرُ من وكيلٍ لا يعرف الجواب. والصمت هنا يُفحص
 * بأثره لا بحالته: **هل توقّف فعلًا عن الردّ بعد التحويل؟**
 */
class HandoffAndKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private MessagingChannel $channel;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['ai_agent.enabled' => true]);

        $this->channel = MessagingChannel::create([
            'provider' => 'whatsapp', 'name' => 'رقم', 'external_id' => '1',
            'is_active' => true, 'ai_enabled' => true,
        ]);
        $contact = ChannelContact::create([
            'channel_id' => $this->channel->id, 'external_id' => '970599123456', 'last_inbound_at' => now(),
        ]);
        $this->conversation = Conversation::create([
            'channel_contact_id' => $contact->id,
            'status_id' => ConversationStatus::defaultId(),
            'last_message_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    // ────────── أداة التحويل ──────────

    /** الأداة تُسلّم المحادثة وتُسجّل السبب. */
    public function test_the_tool_hands_the_conversation_over(): void
    {
        $tool = app(EscalateToHumanTool::class);
        $tool->setConversation($this->conversation);

        $result = $tool->handle(['reason' => 'complaint', 'note' => 'الزبون غاضب من التأخير']);

        $fresh = $this->conversation->fresh();

        $this->assertTrue($result['handed_off']);
        $this->assertSame(Conversation::AI_HANDED_OFF, $fresh->ai_mode);
        $this->assertStringContainsString('complaint', $fresh->handoff_reason);
        $this->assertStringContainsString('غاضب', $fresh->handoff_reason);
    }

    /** والسبب المجهول يعود إلى «تعذّر الجواب» لا يُكتب كما جاء. */
    public function test_an_unknown_reason_falls_back(): void
    {
        $tool = app(EscalateToHumanTool::class);
        $tool->setConversation($this->conversation);

        $tool->handle(['reason' => 'حاجة غريبة']);

        $this->assertStringContainsString('cannot_answer', $this->conversation->fresh()->handoff_reason);
    }

    /**
     * وبعد التحويل يصمت الوكيل — لا تُجدوَل له مهمّة أصلًا.
     *
     * الفحص على الأثر لا على العمود: محادثةٌ «محوّلة» يردّ عليها الوكيل ليست
     * محوّلة.
     */
    public function test_the_agent_goes_silent_after_a_handoff(): void
    {
        Queue::fake();

        $tool = app(EscalateToHumanTool::class);
        $tool->setConversation($this->conversation);
        $tool->handle(['reason' => 'complaint']);

        app(MessageBuffer::class)->schedule($this->conversation->id);

        Queue::assertNothingPushed();
    }

    /** والأداة مسجّلة فيراها النموذج. */
    public function test_the_tool_is_registered(): void
    {
        $this->assertTrue(app(ToolRegistry::class)->has('escalate_to_human'));
    }

    // ────────── مفتاح القناة ──────────

    /** إيقاف القناة يُسكت الوكيل عنها كلّها. */
    public function test_turning_the_channel_off_silences_the_agent(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.inbox.channels.toggle_ai', $this->channel))
            ->assertRedirect();

        $this->assertFalse($this->channel->fresh()->ai_enabled);

        app(MessageBuffer::class)->schedule($this->conversation->id);
        Queue::assertNothingPushed();
    }

    /** والإيقاف لا يُعطّل الاستقبال — المفتاح للردّ وحده. */
    public function test_turning_it_off_does_not_stop_receiving(): void
    {
        $this->channel->forceFill(['ai_enabled' => false])->save();

        $this->assertTrue($this->channel->fresh()->is_active);
        $this->assertFalse($this->conversation->fresh()->agentMayReply());
    }

    /** والتشغيل يعيده. */
    public function test_turning_it_back_on_restores_the_agent(): void
    {
        $this->channel->forceFill(['ai_enabled' => false])->save();

        $this->actingAs($this->admin())
            ->post(route('admin.inbox.channels.toggle_ai', $this->channel))
            ->assertRedirect();

        $this->assertTrue($this->channel->fresh()->ai_enabled);
    }

    // ────────── الاستيلاء والإرجاع ──────────

    /** الموظفة تستولي على المحادثة فيصمت الوكيل. */
    public function test_a_human_can_take_the_conversation_over(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.inbox.conversations.handoff', $this->conversation), ['note' => 'بتابع أنا'])
            ->assertRedirect();

        $this->assertSame(Conversation::AI_HANDED_OFF, $this->conversation->fresh()->ai_mode);
    }

    /** ولا يعود الوكيل إلّا بقرارٍ صريح. */
    public function test_the_agent_returns_only_by_an_explicit_decision(): void
    {
        $this->conversation->forceFill([
            'ai_mode' => Conversation::AI_HANDED_OFF,
            'handoff_reason' => 'complaint',
            'handoff_at' => now(),
        ])->save();

        $this->actingAs($this->admin())
            ->post(route('admin.inbox.conversations.resume', $this->conversation))
            ->assertRedirect();

        $fresh = $this->conversation->fresh();

        $this->assertSame(Conversation::AI_ACTIVE, $fresh->ai_mode);
        $this->assertNull($fresh->handoff_reason);
        $this->assertTrue($fresh->agentMayReply());
    }

    // ────────── الصلاحيات ──────────

    /** إطفاء الوكيل قرارٌ إداريّ محصورٌ بمدير النظام في مرحلة التجربة. */
    public function test_only_the_system_admin_may_toggle_the_agent(): void
    {
        foreach (['manager', 'sales', 'sales_supervisor'] as $role) {
            $this->actingAs($this->withRole($role))
                ->post(route('admin.inbox.channels.toggle_ai', $this->channel))
                ->assertForbidden();
        }

        $this->assertTrue($this->channel->fresh()->ai_enabled);
    }

    /** والتحويل كذلك في مرحلة التجربة. */
    public function test_only_the_system_admin_may_hand_over_during_the_trial(): void
    {
        $this->actingAs($this->withRole('sales'))
            ->post(route('admin.inbox.conversations.handoff', $this->conversation))
            ->assertForbidden();
    }
}
