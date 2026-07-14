<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryStatusService;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use App\Support\Integrations\Shipping\OpostDeliveryProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryStatusEngineTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private DeliveryStatusService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        // ربط مزوّد Opost (تعيين الحالات) — منطق المزوّد خارج وحدات الأعمال.
        app()->bind(DeliveryProviderInterface::class, fn () => new OpostDeliveryProvider);
        $this->svc = app(DeliveryStatusService::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function order(?User $rep = null, float $price = 100, float $qty = 2): Order
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, 60);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
            'assigned_to' => $rep?->id,
        ], [['variant_id' => $variant->fresh()->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => 0]], 2026);
        $order->items->each->update(['wholesale_cost_snapshot' => 60]);

        return $order->fresh('items');
    }

    private function shipment(?User $rep = null, ?int $providerId = null): Shipment
    {
        $order = $this->order($rep);

        return Shipment::create([
            'number' => 'SHP-T-'.$order->id,
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'status' => 'not_shipped',
            'recipient_name' => 'x', 'recipient_phone' => '0500000000',
            'delivery_provider_id' => $providerId,
        ])->fresh();
    }

    // ---- الحالة القانونية والانتقالات ----

    public function test_new_shipment_starts_at_canonical_draft(): void
    {
        $this->assertEquals(DeliveryStatus::DRAFT, $this->shipment()->delivery_status);
    }

    public function test_provider_cancel_auto_cancels_the_order(): void
    {
        // إلغاء المزوّد للشحنة (webhook/مزامنة) يُلغي الطلب تلقائيًا.
        $provider = DeliveryProvider::create(['name' => 'Opost', 'code' => 'opost', 'driver' => 'opost']);
        $shipment = $this->shipment(null, $provider->id);

        $this->svc->applyProviderStatus($shipment, 'cancelled', ['event_id' => 'c1']);

        $this->assertEquals(DeliveryStatus::CANCELLED, $shipment->fresh()->delivery_status);
        $this->assertSame('cancelled', $shipment->order->fresh()->status);
    }

    public function test_legal_manual_path_records_history_with_actor(): void
    {
        $actor = $this->actor('delivery_ops');
        $s = $this->shipment();

        $this->svc->submit($s, ['actor' => $actor, 'note' => 'ready']);
        $this->svc->pickup($s, ['actor' => $actor]);

        $s->refresh();
        $this->assertEquals(DeliveryStatus::PICKED_UP, $s->delivery_status);
        $this->assertEquals(2, $s->deliveryTransitions()->count());
        $first = $s->deliveryTransitions()->first();
        $this->assertEquals(DeliveryStatus::DRAFT, $first->from_status);
        $this->assertEquals(DeliveryStatus::READY_FOR_PICKUP, $first->to_status);
        $this->assertEquals($actor->id, $first->actor_id);
        $this->assertEquals('user', $first->actor_type);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $s = $this->shipment();
        $this->expectException(ValidationException::class);
        $this->svc->pickup($s); // draft → picked_up ممنوع (يجب submit أولًا).
    }

    public function test_on_hold_requires_categorized_reason(): void
    {
        $s = $this->shipment();
        $this->svc->submit($s);
        $this->svc->pickup($s);

        $this->expectException(ValidationException::class);
        $this->svc->transitionTo($s, DeliveryStatus::ON_HOLD, ['source' => 'user']); // بلا سبب.
    }

    public function test_on_hold_stores_reason_and_is_reportable(): void
    {
        $s = $this->shipment();
        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->putOnHold($s, 'wrong_phone', 'الرقم مغلق');

        $this->assertEquals('wrong_phone', $s->fresh()->on_hold_reason);
        $report = $this->svc->holdReasonReport();
        $this->assertEquals(1, $report->firstWhere('reason_code', 'wrong_phone')->total);
    }

    public function test_resume_from_hold_clears_reason(): void
    {
        $s = $this->shipment();
        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->putOnHold($s, 'customer_no_answer');
        $this->svc->resume($s);

        $this->assertEquals(DeliveryStatus::PICKED_UP, $s->fresh()->delivery_status);
        $this->assertNull($s->fresh()->on_hold_reason);
    }

    // ---- فصل المزوّد عن القانوني ----

    public function test_provider_status_maps_to_canonical_and_stays_separate(): void
    {
        $provider = DeliveryProvider::create(['name' => 'Opost', 'code' => 'opost', 'driver' => 'opost']);
        $s = $this->shipment(providerId: $provider->id);

        $this->svc->applyProviderStatus($s, 'submit', ['event_id' => 'e1']);
        $s->refresh();

        // القانوني نظيف؛ المزوّد خام محفوظ منفصلًا.
        $this->assertEquals(DeliveryStatus::READY_FOR_PICKUP, $s->delivery_status);
        $this->assertEquals('submit', $s->provider_status);
        $this->assertNotEquals($s->delivery_status, $s->provider_status);
        $this->assertEquals(1, $s->providerTransitions()->count());
        $this->assertEquals(1, $s->deliveryTransitions()->where('actor_type', 'provider')->count());
    }

    public function test_opost_cod_pickup_maps_to_delivered_not_returned(): void
    {
        // فخّ الأسماء: Opost cod_pickup = سُلّم للعميل (لا مرتجع).
        $mapper = new OpostDeliveryProvider;
        $this->assertEquals(DeliveryStatus::DELIVERED_COD_HELD, $mapper->mapProviderStatus('cod_pickup'));
        $this->assertEquals(DeliveryStatus::RETURN_IN_TRANSIT, $mapper->mapProviderStatus('delivered'));
        $this->assertEquals(DeliveryStatus::CLOSED, $mapper->mapProviderStatus('close'));
    }

    public function test_provider_event_is_idempotent(): void
    {
        $provider = DeliveryProvider::create(['name' => 'Opost', 'code' => 'opost', 'driver' => 'opost']);
        $s = $this->shipment(providerId: $provider->id);

        $this->svc->applyProviderStatus($s, 'submit', ['event_id' => 'dup']);
        $this->svc->applyProviderStatus($s, 'submit', ['event_id' => 'dup']);

        $this->assertEquals(1, $s->providerTransitions()->count());
    }

    public function test_illegal_provider_transition_keeps_provider_history_only(): void
    {
        $provider = DeliveryProvider::create(['name' => 'Opost', 'code' => 'opost', 'driver' => 'opost']);
        $s = $this->shipment(providerId: $provider->id);

        // close مباشرةً من draft غير قانوني داخليًا — تُحفظ حالة المزوّد ولا تتغيّر القانونية.
        $this->svc->applyProviderStatus($s, 'close', ['event_id' => 'x']);
        $s->refresh();

        $this->assertEquals(DeliveryStatus::DRAFT, $s->delivery_status);
        $this->assertEquals('close', $s->provider_status);
        $this->assertEquals(1, $s->providerTransitions()->count());
        $this->assertEquals(0, $s->deliveryTransitions()->count());
    }

    // ---- الإغلاق المالي (CLOSE) — نقطة الاستحقاق الوحيدة ----

    public function test_delivered_cod_held_does_not_make_commission_eligible(): void
    {
        $rep = $this->actor('sales');
        $s = $this->shipment($rep);
        app(CommissionService::class)->accrueForOrder($s->order); // استحقاق عند التسليم (pending)

        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->markDeliveredCodHeld($s); // سُلّم، النقد لدى المندوب

        $entry = CommissionEntry::where('order_id', $s->order_id)->first();
        $this->assertEquals('pending', $entry->state); // ليست مستحقّة بعد
        $this->assertNull($s->order->fresh()->settled_at);
    }

    public function test_close_settles_order_and_makes_commissions_eligible_only(): void
    {
        $finance = $this->actor('finance');
        $rep = $this->actor('sales');
        $s = $this->shipment($rep);

        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->markDeliveredCodHeld($s);
        $this->svc->markFundsAtAccounting($s);
        $this->svc->close($s, $finance);

        $s->refresh();
        $this->assertEquals(DeliveryStatus::CLOSED, $s->delivery_status);
        $this->assertNotNull($s->closed_at);
        $this->assertNotNull($s->order->fresh()->settled_at);

        $entry = CommissionEntry::where('order_id', $s->order_id)->first();
        $this->assertEquals('eligible', $entry->state); // مستحقّة — لا مدفوعة
        $this->assertEquals('2.00', $entry->amount);    // 1% من 200
        // لا دفع تلقائي.
        $this->assertEquals(0.0, app(CommissionService::class)->statement($rep->id, 'sales')['paid']);
    }

    public function test_full_return_path_reaches_close(): void
    {
        $finance = $this->actor('finance');
        $s = $this->shipment($this->actor('sales'));

        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->markReturningToCourier($s);
        $this->svc->markReturnInTransit($s);
        $this->svc->close($s, $finance);

        $this->assertEquals(DeliveryStatus::CLOSED, $s->fresh()->delivery_status);
        $this->assertNotNull($s->order->fresh()->settled_at);
    }

    // ---- التفويض والواجهات ----

    public function test_authorization_on_transition_and_close(): void
    {
        $provider = DeliveryProvider::create(['name' => 'Opost', 'code' => 'opost', 'driver' => 'opost']);
        $s = $this->shipment(providerId: $provider->id);

        Sanctum::actingAs($this->actor('sales')); // view فقط — لا manage/close/sync
        $this->postJson("/api/v1/shipping/shipments/{$s->uuid}/delivery/transition", ['to' => 'ready_for_pickup'])->assertForbidden();
        $this->postJson("/api/v1/shipping/shipments/{$s->uuid}/delivery/close")->assertForbidden();
        $this->postJson("/api/v1/shipping/shipments/{$s->uuid}/delivery/provider-status", ['provider_status' => 'submit'])->assertForbidden();

        Sanctum::actingAs($this->actor('delivery_ops')); // manage + sync، لا close
        $this->postJson("/api/v1/shipping/shipments/{$s->uuid}/delivery/transition", ['to' => 'ready_for_pickup'])->assertOk();
        $this->postJson("/api/v1/shipping/shipments/{$s->uuid}/delivery/close")->assertForbidden();
    }

    public function test_admin_delivery_pages_render(): void
    {
        $ops = $this->actor('delivery_ops');
        $ops->update(['email_verified_at' => now()]);
        $s = $this->shipment();
        $this->svc->submit($s);

        $this->actingAs($ops)->get('/admin/shipping/delivery')->assertOk()->assertSee(__('delivery.engine'));
        $this->actingAs($ops)->get("/admin/shipping/delivery/{$s->uuid}")
            ->assertOk()->assertSee(__('delivery.status.ready_for_pickup'));
    }
}
