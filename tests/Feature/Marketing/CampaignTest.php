<?php

namespace Tests\Feature\Marketing;

use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Jobs\SendCampaignMessageJob;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\MessageSuppression;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Sales\Events\OrderDelivered;
use App\Support\Integrations\Messaging\FakeMessagingProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private CampaignService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // مزوّد وهمي — لا رسائل خارجية حقيقية أثناء الاختبارات (المتطلّب).
        config(['messaging.channels.whatsapp' => 'fake']);
        FakeMessagingProvider::reset();
        $this->svc = app(CampaignService::class);
    }

    private function campaign(array $overrides = []): Campaign
    {
        return Campaign::create(array_merge([
            'name' => 'متابعة بعد التسليم',
            'use_case' => 'post_delivery',
            'channel' => 'whatsapp',
            'status' => 'active',
            'trigger_type' => 'event',
            'trigger_event' => 'order_delivered',
            'body_ar' => 'مرحبًا {{name}}، شكرًا لطلبك!',
        ], $overrides));
    }

    private function customer(array $prefs = ['whatsapp' => true]): Customer
    {
        return Customer::factory()->create([
            'primary_phone' => '966500000001',
            'communication_preferences' => $prefs,
        ]);
    }

    public function test_dispatches_to_opted_in_customer_via_provider(): void
    {
        $campaign = $this->campaign();
        $customer = $this->customer();

        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:1');

        $message = CampaignMessage::first();
        $this->assertSame('sent', $message->status);
        $this->assertSame('مرحبًا '.$customer->name.'، شكرًا لطلبك!', $message->body);
        $this->assertCount(1, FakeMessagingProvider::$sent);
    }

    public function test_respects_consent_opt_in(): void
    {
        $this->campaign();
        $customer = $this->customer(['whatsapp' => false]); // لم يوافق

        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:2');

        $this->assertSame('no_consent', CampaignMessage::first()->status);
        $this->assertCount(0, FakeMessagingProvider::$sent);
    }

    public function test_respects_suppression_opt_out(): void
    {
        $this->campaign();
        $customer = $this->customer();
        MessageSuppression::create(['customer_id' => $customer->id, 'channel' => 'whatsapp', 'reason' => 'test', 'created_at' => now()]);

        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:3');

        $this->assertSame('suppressed', CampaignMessage::first()->status);
    }

    public function test_is_idempotent_on_reference(): void
    {
        $this->campaign();
        $customer = $this->customer();

        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:4');
        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:4');

        $this->assertSame(1, CampaignMessage::count());
        $this->assertCount(1, FakeMessagingProvider::$sent);
    }

    public function test_respects_quiet_hours(): void
    {
        $this->travelTo(now()->setTime(23, 0));
        $this->campaign(['quiet_start' => 22, 'quiet_end' => 7]);
        $customer = $this->customer();

        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:5');

        $this->assertSame('quiet_hours', CampaignMessage::first()->status);
        $this->assertCount(0, FakeMessagingProvider::$sent);
        $this->travelBack();
    }

    public function test_respects_frequency_cap(): void
    {
        $campaignA = $this->campaign(['frequency_cap_days' => 7]);
        $customer = $this->customer();

        // أول إرسال ينجح.
        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:6a');
        // ثانٍ خلال النافذة يُكبح.
        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:6b');

        $statuses = CampaignMessage::pluck('status')->all();
        $this->assertContains('sent', $statuses);
        $this->assertContains('frequency_capped', $statuses);
    }

    public function test_test_send_bypasses_consent_but_not_suppression(): void
    {
        $campaign = $this->campaign();
        $noConsent = $this->customer(['whatsapp' => false]);
        $message = $this->svc->test($campaign, $noConsent);
        $this->assertSame('sent', $message->status); // الاختبار يتجاوز الموافقة

        $suppressed = $this->customer(['whatsapp' => false]);
        MessageSuppression::create(['customer_id' => $suppressed->id, 'channel' => 'whatsapp', 'reason' => 'x', 'created_at' => now()]);
        $this->assertSame('suppressed', $this->svc->test($campaign, $suppressed)->status);
    }

    public function test_transition_guard_blocks_invalid_transitions(): void
    {
        $campaign = $this->campaign(['status' => 'draft']);
        $this->expectException(ValidationException::class);
        $this->svc->transition($campaign, 'active'); // draft لا ينتقل مباشرة إلى active
    }

    public function test_event_subscriber_is_registered(): void
    {
        // التأكد من ربط الحدث بالمحفّز (بلا بناء طلب كامل).
        $listeners = app('events')->getListeners(OrderDelivered::class);
        $this->assertNotEmpty($listeners);
    }

    public function test_external_send_is_queued_not_synchronous(): void
    {
        Queue::fake();
        $this->campaign();
        $customer = $this->customer();

        $this->svc->handleTrigger('order_delivered', $customer, [], 'order:queued');

        // الإرسال الخارجي مُجدوَل (لا يحجب الطلب)؛ الرسالة محفوظة بحالة queued.
        Queue::assertPushed(SendCampaignMessageJob::class, 1);
        $this->assertSame('queued', CampaignMessage::first()->status);
        $this->assertCount(0, FakeMessagingProvider::$sent); // لم يُرسَل تزامنيًا
    }
}
