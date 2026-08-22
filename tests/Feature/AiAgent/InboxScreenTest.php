<?php

namespace Tests\Feature\AiAgent;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Support\AdminNavigation;
use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصندوق الموحّد — أوّل شاشةٍ يُرى فيها الوكيل يعمل.
 *
 * غرضها الأول **الرقابة** لا الخدمة: أن يُقرأ ما قاله الوكيل بالحرف، وأن
 * يُوقَف بضغطةٍ حين يخطئ. ولذلك تُفحص هنا ثلاثة: أن الكلام يظهر، وأن قائله
 * يُعرَف، وأن المفتاح في متناول اليد.
 */
class InboxScreenTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['ai_agent.enabled' => true]);

        $channel = MessagingChannel::create([
            'provider' => 'whatsapp', 'name' => 'رقم المتجر', 'external_id' => '1',
            'is_active' => true, 'ai_enabled' => true,
        ]);
        $contact = ChannelContact::create([
            'channel_id' => $channel->id, 'external_id' => '970599123456',
            'display_name' => 'أبو محمد', 'last_inbound_at' => now(),
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

    private function message(string $body, string $direction = Message::IN, string $sender = 'customer'): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'external_id' => 'wamid.'.uniqid(),
            'direction' => $direction,
            'sender_type' => $sender,
            'type' => 'text',
            'body' => $body,
            'sent_at' => now(),
        ]);
    }

    /** القائمة تفتح وتعرض الزبون. */
    public function test_the_list_opens_and_shows_the_customer(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertSee('أبو محمد', false)
            ->assertSee('970599123456', false);
    }

    /** والخيط يعرض ما قاله الزبون وما ردّ به الوكيل. */
    public function test_the_thread_shows_both_voices(): void
    {
        $this->message('هذا بكم؟');
        $this->message('أهلًا فيك، بحكيلك', Message::OUT, 'ai');

        $this->actingAs($this->admin())
            ->get(route('admin.inbox.show', $this->conversation))
            ->assertOk()
            ->assertSee('هذا بكم؟', false)
            ->assertSee('أهلًا فيك، بحكيلك', false)
            ->assertSee('الوكيل', false);
    }

    /** ومفتاح إيقاف الوكيل ظاهرٌ في الشاشة لا مخبوءٌ في الإعدادات. */
    public function test_the_kill_switch_is_on_the_screen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertSee('رقم المتجر', false)
            ->assertSee('اضغط للإيقاف', false);
    }

    /**
     * والردّ اليدوي يُسكت الوكيل عن المحادثة.
     *
     * موظفةٌ تكتب ووكيلٌ يردّ في الخيط نفسه يُنتجان صوتين متناقضين أمام
     * الزبون.
     */
    public function test_a_manual_reply_pauses_the_agent(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.inbox.reply', $this->conversation), ['body' => 'أهلًا، كيف بقدر أساعدك؟'])
            ->assertRedirect();

        $this->assertSame(Conversation::AI_PAUSED, $this->conversation->fresh()->ai_mode);
    }

    /**
     * وخارج نافذة الـ24 ساعة يُشرح السبب بدل أن يبدو عطلًا.
     */
    public function test_outside_the_window_the_reason_is_explained(): void
    {
        $this->conversation->contact->forceFill(['last_inbound_at' => now()->subDays(2)])->save();

        $this->actingAs($this->admin())
            ->get(route('admin.inbox.show', $this->conversation->fresh()))
            ->assertOk()
            ->assertSee('24 ساعة', false);
    }

    /** والفلاتر تُضيّق القائمة. */
    public function test_the_handed_off_filter_narrows_the_list(): void
    {
        $this->conversation->forceFill([
            'ai_mode' => Conversation::AI_HANDED_OFF, 'handoff_reason' => 'complaint',
        ])->save();

        $this->actingAs($this->admin())
            ->get(route('admin.inbox.index', ['filter' => 'handed_off']))
            ->assertOk()
            ->assertSee('أبو محمد', false);

        $this->actingAs($this->admin())
            ->get(route('admin.inbox.index', ['filter' => 'ai']))
            ->assertOk()
            ->assertDontSee('أبو محمد', false);
    }

    /** والحالة تُحفظ من القائمة المنسدلة. */
    public function test_the_status_can_be_changed(): void
    {
        $status = ConversationStatus::where('is_active', true)->orderByDesc('id')->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('admin.inbox.status', $this->conversation), ['status_id' => $status->id])
            ->assertRedirect();

        $this->assertSame($status->id, $this->conversation->fresh()->status_id);
    }

    // ────────── الصلاحيات ──────────

    /** الصندوق محصورٌ بمدير النظام في مرحلة التجربة. */
    public function test_the_inbox_is_admin_only_during_the_trial(): void
    {
        foreach (['manager', 'sales', 'sales_supervisor', 'affiliate'] as $role) {
            $this->actingAs($this->withRole($role))
                ->get(route('admin.inbox.index'))
                ->assertForbidden();
        }
    }

    /** ولا يظهر بنده في قائمة غيره. */
    public function test_the_sidebar_hides_it_from_everyone_else(): void
    {
        $this->actingAs($this->withRole('manager'));

        $titles = collect(AdminNavigation::groups())
            ->flatMap(fn (array $g) => collect($g['items'])->pluck('label'))->all();

        $this->assertNotContains('الصندوق الموحّد', $titles);
    }

    /** ويراه مدير النظام. */
    public function test_the_sidebar_shows_it_to_the_system_admin(): void
    {
        $this->actingAs($this->admin());

        $titles = collect(AdminNavigation::groups())
            ->flatMap(fn (array $g) => collect($g['items'])->pluck('label'))->all();

        $this->assertContains('الصندوق الموحّد', $titles);
    }
}
