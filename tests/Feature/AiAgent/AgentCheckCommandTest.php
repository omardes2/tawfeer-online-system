<?php

namespace Tests\Feature\AiAgent;

use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Models\MessagingChannel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فحص جاهزية الوكيل، وإنشاء قناة واتساب.
 *
 * ستّة أشياء مستقلّة يعتمد عليها الوكيل، وسقوط أيٍّ منها يُنتج **العَرَض
 * نفسه**: لا ردّ. فيُفحص الكلّ في سطرٍ واحد.
 *
 * وإنشاء صفّ القناة أهمّها: الاستقبال يبحث عن قناةٍ بمعرّف الرقم، ولا يجدها
 * فيُسقط رسالة الزبون صامتًا — ولم يكن لإنشائها طريقٌ في النظام أصلًا.
 */
class AgentCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config([
            'messaging.whatsapp.phone_number_id' => '123456789',
            'messaging.whatsapp.token' => 'tok',
            'messaging.webhooks.verify_token' => 'vt',
            'messaging.webhooks.app_secret' => 'sec',
            'ai_agent.api_key' => 'sk-test',
            'ai_agent.enabled' => true,
        ]);
    }

    /** الأمر ينشئ القناة من إعدادات البيئة. */
    public function test_it_creates_the_whatsapp_channel(): void
    {
        $this->assertSame(0, MessagingChannel::count());

        $this->artisan('ai-agent:check', ['--create-channel' => true])->assertSuccessful();

        $channel = MessagingChannel::firstOrFail();

        $this->assertSame('whatsapp', $channel->provider);
        $this->assertSame('123456789', $channel->external_id);
        $this->assertTrue($channel->is_active);
    }

    /**
     * والقناة تُنشأ **مطفأة**.
     *
     * إنشاؤها إجراءٌ تقنيّ، وتشغيل الوكيل قرارٌ إداريّ يُتَّخذ بعد قراءة ما
     * سيقوله — لا أثرًا جانبيًّا لأمرِ إعداد.
     */
    public function test_the_new_channel_starts_switched_off(): void
    {
        $this->artisan('ai-agent:check', ['--create-channel' => true])->assertSuccessful();

        $this->assertFalse(MessagingChannel::firstOrFail()->ai_enabled);
    }

    /** ولا تُنشأ مرّتين. */
    public function test_running_it_twice_creates_one_channel(): void
    {
        $this->artisan('ai-agent:check', ['--create-channel' => true])->assertSuccessful();
        $this->artisan('ai-agent:check', ['--create-channel' => true])->assertSuccessful();

        $this->assertSame(1, MessagingChannel::count());
    }

    /** ولا تُنشأ بلا معرّف رقم. */
    public function test_it_refuses_without_a_phone_number_id(): void
    {
        config(['messaging.whatsapp.phone_number_id' => '']);

        $this->artisan('ai-agent:check', ['--create-channel' => true])
            ->expectsOutputToContain('is MISSING')
            ->assertSuccessful();

        $this->assertSame(0, MessagingChannel::count());
    }

    /** والفحص يقول ما ينقص بدل أن يصمت. */
    public function test_it_names_what_is_missing(): void
    {
        config(['ai_agent.api_key' => null, 'ai_agent.enabled' => false]);

        $this->artisan('ai-agent:check')
            ->expectsOutputToContain('need attention')
            ->assertSuccessful();
    }

    /** ويعلن الجاهزية حين يكتمل كل شيء. */
    public function test_it_reports_readiness_when_everything_is_set(): void
    {
        MessagingChannel::create([
            'provider' => 'whatsapp', 'name' => 'واتساب المتجر', 'external_id' => '123456789',
            'is_active' => true, 'ai_enabled' => true,
        ]);

        ProductKnowledge::create([
            'product_id' => Product::factory()->create()->id,
            'selling_points' => ['نقطة'], 'is_ready' => true,
        ]);

        config(['queue.default' => 'database', 'messaging.channels.whatsapp' => 'whatsapp_cloud']);

        $this->artisan('ai-agent:check')
            ->expectsOutputToContain('ALL OK')
            ->assertSuccessful();
    }

    /**
     * ويحذّر من `sync`.
     *
     * الردّ حينها يجري داخل الـwebhook فيتجاوز المهلة، وتُعيد ميتا الإرسال
     * فيردّ الوكيل مرّتين.
     */
    public function test_it_warns_about_the_sync_queue(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('ai-agent:check')
            ->expectsOutputToContain('queue:work')
            ->assertSuccessful();
    }
}
