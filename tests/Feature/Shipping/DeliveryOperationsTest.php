<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\DeliveryExceptionCategory;
use App\Modules\Shipping\Models\DeliveryProviderEvent;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryExceptionService;
use App\Modules\Shipping\Services\DeliveryFeeService;
use App\Modules\Shipping\Services\DeliverySyncService;
use App\Modules\Shipping\Services\ShipmentTimelineService;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\Integrations\Shipping\DeliveryProviderManager;
use App\Support\Integrations\Shipping\OpostDeliveryProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeTrackingDeliveryProvider;
use Tests\TestCase;

class DeliveryOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        FakeTrackingDeliveryProvider::$trackStatus = null;
        FakeTrackingDeliveryProvider::$throw = false;
        config(['shipping.drivers.faketrack.delivery' => FakeTrackingDeliveryProvider::class]);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function order(): Order
    {
        return app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [], 2026);
    }

    private function shipment(string $driver = 'opost', array $attrs = []): Shipment
    {
        $order = $this->order();
        $provider = DeliveryProvider::firstOrCreate(['code' => $driver], ['name' => ucfirst($driver), 'driver' => $driver]);

        return Shipment::create(array_merge([
            'number' => 'SHP-OP-'.$order->id.'-'.uniqid(),
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'status' => 'not_shipped',
            'recipient_name' => 'x', 'recipient_phone' => '0500000000',
            'delivery_provider_id' => $provider->id,
            'tracking_number' => 'TRK-'.$order->id,
        ], $attrs))->fresh();
    }

    // ---- 1) محرّك الاستثناءات ----

    public function test_open_exception_computes_sla_and_workflow(): void
    {
        $svc = app(DeliveryExceptionService::class);
        $actor = $this->actor('delivery_ops');
        $s = $this->shipment();
        $cat = DeliveryExceptionCategory::where('code', 'contact_issue')->first(); // sla 24h

        $ex = $svc->open($s, $cat, ['actor' => $actor, 'note' => 'أول محاولة']);
        $this->assertEquals('open', $ex->status);
        $this->assertNotNull($ex->sla_due_at);
        $this->assertEquals(1, $ex->notes()->count());

        $svc->assign($ex, $actor, $actor);
        $this->assertEquals('in_progress', $ex->fresh()->status);

        $svc->resolve($ex, 'تمّ التواصل', $actor);
        $this->assertEquals('resolved', $ex->fresh()->status);
        $this->assertNotNull($ex->fresh()->resolved_at);

        $svc->reopen($ex, $actor);
        $this->assertEquals('reopened', $ex->fresh()->status);
        $this->assertEquals(1, $ex->fresh()->reopened_count);
    }

    public function test_cannot_reopen_unresolved_exception(): void
    {
        $svc = app(DeliveryExceptionService::class);
        $s = $this->shipment();
        $cat = DeliveryExceptionCategory::where('code', 'other')->first();
        $ex = $svc->open($s, $cat);

        $this->expectException(ValidationException::class);
        $svc->reopen($ex);
    }

    public function test_scheduled_escalation_of_overdue_exceptions(): void
    {
        $svc = app(DeliveryExceptionService::class);
        $s = $this->shipment();
        $cat = DeliveryExceptionCategory::where('code', 'refused')->first(); // escalate_after 24h
        $ex = $svc->open($s, $cat);

        $this->assertEquals(0, $svc->escalateOverdue()); // ليست متأخّرة بعد

        $this->travel(25)->hours();
        $this->assertEquals(1, $svc->escalateOverdue());
        $this->assertEquals('escalated', $ex->fresh()->status);
        $this->assertNotNull($ex->fresh()->escalated_at);
        $this->assertEquals(0, $svc->escalateOverdue()); // idempotent (لا تُصعَّد مرّتين)
        $this->travelBack();
    }

    public function test_exception_api_authorization(): void
    {
        $s = $this->shipment();
        Sanctum::actingAs($this->actor('sales')); // view فقط
        $this->postJson("/api/v1/shipping/shipments/{$s->uuid}/exceptions", ['category' => 'other'])->assertForbidden();

        Sanctum::actingAs($this->actor('delivery_ops'));
        $this->postJson("/api/v1/shipping/shipments/{$s->uuid}/exceptions", ['category' => 'other'])->assertCreated();
    }

    // ---- 2) بنية الـWebhook ----

    private function signedWebhook(string $provider, array $payload, ?string $secret): TestResponse
    {
        $raw = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X-OPOST-SIGNATURE'] = hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', "/api/v1/webhooks/delivery/{$provider}", [], [], [], $server, $raw);
    }

    public function test_webhook_valid_signature_maps_and_processes(): void
    {
        config(['delivery.webhook.secrets.opost' => 'sekret']);
        $s = $this->shipment('opost', ['external_id' => 'EX-1']);

        $res = $this->signedWebhook('opost', ['event_id' => 'w1', 'tracking_number' => 'EX-1', 'status' => 'submit'], 'sekret');
        $res->assertOk()->assertJson(['status' => 'processed']);

        $this->assertEquals(DeliveryStatus::READY_FOR_PICKUP, $s->fresh()->delivery_status);
        $this->assertEquals('submit', $s->fresh()->provider_status);
        $this->assertDatabaseHas('delivery_provider_events', ['event_id' => 'w1', 'channel' => 'webhook', 'status' => 'processed']);
    }

    public function test_webhook_invalid_signature_is_rejected_and_logged(): void
    {
        config(['delivery.webhook.secrets.opost' => 'sekret']);
        $s = $this->shipment('opost', ['external_id' => 'EX-2']);

        $raw = json_encode(['event_id' => 'bad', 'tracking_number' => 'EX-2', 'status' => 'submit']);
        $res = $this->call('POST', '/api/v1/webhooks/delivery/opost', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-OPOST-SIGNATURE' => 'wrong',
        ], $raw);

        $res->assertStatus(401);
        $this->assertEquals(DeliveryStatus::DRAFT, $s->fresh()->delivery_status); // لم تتغيّر
        $this->assertDatabaseHas('delivery_provider_events', ['event_id' => 'bad', 'status' => 'unverified']);
    }

    public function test_webhook_duplicate_event_is_protected(): void
    {
        config(['delivery.webhook.secrets.opost' => 'sekret']);
        $s = $this->shipment('opost', ['external_id' => 'EX-3']);

        $this->signedWebhook('opost', ['event_id' => 'dup', 'tracking_number' => 'EX-3', 'status' => 'submit'], 'sekret')->assertOk();
        $this->signedWebhook('opost', ['event_id' => 'dup', 'tracking_number' => 'EX-3', 'status' => 'submit'], 'sekret')
            ->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertEquals(1, DeliveryProviderEvent::where('event_id', 'dup')->where('status', 'processed')->count());
        $this->assertEquals(1, $s->fresh()->providerTransitions()->count());
    }

    public function test_webhook_unknown_shipment_is_ignored_but_logged(): void
    {
        config(['delivery.webhook.secrets.opost' => 'sekret']);
        DeliveryProvider::firstOrCreate(['code' => 'opost'], ['name' => 'Opost', 'driver' => 'opost']);

        $this->signedWebhook('opost', ['event_id' => 'u1', 'tracking_number' => 'MISSING', 'status' => 'submit'], 'sekret')
            ->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertDatabaseHas('delivery_provider_events', ['event_id' => 'u1', 'status' => 'ignored']);
    }

    // ---- 3) المزامنة المجدولة ----

    public function test_sync_applies_status_and_is_idempotent(): void
    {
        $svc = app(DeliverySyncService::class);
        $s = $this->shipment('faketrack', ['external_id' => 'SX-1']);

        FakeTrackingDeliveryProvider::$trackStatus = 'submit';
        $this->assertEquals('synced', $svc->syncShipment($s->fresh()));
        $this->assertEquals(DeliveryStatus::READY_FOR_PICKUP, $s->fresh()->delivery_status);

        // نفس الحالة ⇒ لا استيعاب مكرّر.
        $this->assertEquals('skipped', $svc->syncShipment($s->fresh()));
        $this->assertEquals(1, $s->fresh()->providerTransitions()->count());
        $this->assertDatabaseHas('delivery_provider_events', ['channel' => 'sync', 'status' => 'processed']);
    }

    public function test_sync_detects_provider_inconsistency(): void
    {
        $svc = app(DeliverySyncService::class);
        $s = $this->shipment('faketrack', ['external_id' => 'SX-2']);

        FakeTrackingDeliveryProvider::$trackStatus = 'close'; // close من draft غير قانوني
        $this->assertEquals('inconsistent', $svc->syncShipment($s->fresh()));
        $this->assertEquals('inconsistent', $s->fresh()->sync_status);
        $this->assertDatabaseHas('delivery_provider_events', ['channel' => 'sync', 'status' => 'inconsistent']);
    }

    public function test_sync_retries_failed_and_counts_attempts(): void
    {
        $svc = app(DeliverySyncService::class);
        $s = $this->shipment('faketrack', ['external_id' => 'SX-3']);

        FakeTrackingDeliveryProvider::$throw = true;
        $this->assertEquals('failed', $svc->syncShipment($s->fresh()));
        $this->assertEquals(1, $s->fresh()->sync_attempts);
        $this->assertEquals('failed', $s->fresh()->sync_status);
    }

    public function test_sync_active_only_touches_non_terminal(): void
    {
        $svc = app(DeliverySyncService::class);
        $active = $this->shipment('faketrack', ['external_id' => 'A-1']);
        $closed = $this->shipment('faketrack', ['external_id' => 'C-1', 'delivery_status' => DeliveryStatus::CLOSED]);

        FakeTrackingDeliveryProvider::$trackStatus = 'submit';
        $counts = $svc->syncActive();

        $this->assertEquals(DeliveryStatus::READY_FOR_PICKUP, $active->fresh()->delivery_status);
        $this->assertNull($closed->fresh()->provider_status); // نهائية ⇒ لم تُمسّ
        $this->assertGreaterThanOrEqual(1, $counts['synced']);
    }

    // ---- 4) محرّك الرسوم ----

    public function test_fee_components_total_and_owners(): void
    {
        $svc = app(DeliveryFeeService::class);
        $s = $this->shipment();

        $svc->addComponent($s, 'delivery', 15, ['owner' => 'customer', 'source' => 'quoted']);
        $svc->addComponent($s, 'cod', 5, ['owner' => 'customer']);
        $svc->addComponent($s, 'remote_area', 10, ['owner' => 'customer']);
        $svc->addComponent($s, 'discount', -8, ['owner' => 'business']);

        $this->assertEquals(22.0, $svc->total($s)); // 15+5+10-8
        $owners = $svc->totalsByOwner($s);
        $this->assertEquals(30.0, $owners['customer']);
        $this->assertEquals(-8.0, $owners['business']);
    }

    public function test_fee_rejects_unknown_type(): void
    {
        $this->expectException(ValidationException::class);
        app(DeliveryFeeService::class)->addComponent($this->shipment(), 'bribe', 100);
    }

    // ---- 5) الخطّ الزمني الموحّد ----

    public function test_timeline_merges_all_sources_chronologically(): void
    {
        config(['delivery.webhook.secrets.opost' => 'sekret']);
        $s = $this->shipment('opost', ['external_id' => 'TL-1']);

        // إجراء مستخدم (حالة داخلية) + حدث مزوّد عبر webhook.
        app(DeliveryProviderManager::class); // تحقّق من توفّر المُحلّل
        $this->signedWebhook('opost', ['event_id' => 'tl', 'tracking_number' => 'TL-1', 'status' => 'submit'], 'sekret')->assertOk();

        $timeline = app(ShipmentTimelineService::class)->build($s->fresh());
        $types = $timeline->pluck('type')->unique()->values()->all();

        $this->assertContains('status', $types);          // حالة داخلية
        $this->assertContains('provider_status', $types);  // حالة مزوّد
        $this->assertContains('webhook', $types);          // حدث webhook
    }

    // ---- 6) تجريد متعدّد المزوّدين ----

    public function test_provider_manager_resolves_driver_by_code(): void
    {
        $manager = app(DeliveryProviderManager::class);
        $this->assertInstanceOf(OpostDeliveryProvider::class, $manager->driver('opost'));
        $this->assertInstanceOf(FakeTrackingDeliveryProvider::class, $manager->driver('faketrack'));
        $this->assertEquals('faketrack', $manager->driver('faketrack')->name());
    }
    // ---- التحديث الفوري عبر webhook بحمولة Opost الحقيقية ----

    /** بلا سرّ مضبوط: الـwebhook يُقبل ويُحدّث فورًا (التوقيع اختياري حتى يُفعّل). */
    public function test_unsigned_webhook_updates_status_instantly(): void
    {
        config(['delivery.webhook.secrets.opost' => null]);
        $s = $this->shipment('opost', ['external_id' => '7424419']);

        $this->signedWebhook('opost', ['event_id' => 'u1', 'id' => '7424419', 'status' => 'submitted'], null)
            ->assertOk()->assertJson(['status' => 'processed']);

        $this->assertEquals(DeliveryStatus::READY_FOR_PICKUP, $s->fresh()->delivery_status);
    }

    /** حمولة Opost الفعلية: كائن الشحنة الكامل والحالة في status_history. */
    public function test_webhook_parses_opost_status_history_payload(): void
    {
        config(['delivery.webhook.secrets.opost' => null]);
        $s = $this->shipment('opost', ['external_id' => '7424419']);

        $payload = [
            'id' => '7424419',
            'status_history' => [
                ['id' => 'h1', 'status' => 'submitted', 'created_at' => '2026-08-11 13:39:02'],
                ['id' => 'h2', 'status' => 'pickup', 'created_at' => '2026-08-11 13:46:45'],
            ],
        ];

        $this->signedWebhook('opost', $payload, null)->assertOk()->assertJson(['status' => 'processed']);

        // تُلتقط الحالة الأحدث (pickup) لا الأقدم.
        $this->assertEquals(DeliveryStatus::PICKED_UP, $s->fresh()->delivery_status);
        $this->assertEquals('pickup', $s->fresh()->provider_status);
    }

    /**
     * سيناريو الطرد 7424419: يصل حدث «delivered» (الراجع دخل فاتورة إرجاع) والشحنة ما
     * تزال ready_for_pickup ⇒ يُسار المسار القانوني بدل رفض التحديث.
     */
    public function test_webhook_jump_walks_legal_path_to_return(): void
    {
        config(['delivery.webhook.secrets.opost' => null]);
        $s = $this->shipment('opost', ['external_id' => '7424419']);

        $this->signedWebhook('opost', ['event_id' => 'p1', 'id' => '7424419', 'status' => 'submitted'], null)->assertOk();
        $this->signedWebhook('opost', ['event_id' => 'p2', 'id' => '7424419', 'status' => 'delivered'], null)
            ->assertOk()->assertJson(['status' => 'processed']);

        $s->refresh();
        $this->assertEquals(DeliveryStatus::RETURN_IN_TRANSIT, $s->delivery_status);
        $this->assertEquals('delivered', $s->provider_status);
    }

    /** المزامنة اليدوية الفورية من صفحة الشحنة تستدعي المزوّد وتحدّث الحالة. */
    public function test_manual_sync_now_endpoint_updates_status(): void
    {
        $s = $this->shipment('faketrack', ['external_id' => 'SYNC-1']);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.shipping.shipments.sync', $s))
            ->assertRedirect();

        $this->assertNotNull($s->fresh()->last_synced_at);
    }
}
