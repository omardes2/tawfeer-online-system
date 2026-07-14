<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Jobs\CancelOrderShipment;
use App\Modules\Shipping\Jobs\DispatchOrderShipment;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Support\DeliveryStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SalesAdminWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin/sales/orders')->assertRedirect('/login');
    }

    public function test_orders_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/sales/orders');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('طلبات البيع');
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/sales/orders/create')->assertOk()->assertSee('طلب بيع جديد');
    }

    public function test_accountant_sees_no_create_button(): void
    {
        $response = $this->actingAs($this->withRole('accountant'))->get('/admin/sales/orders');
        $response->assertOk();
        $response->assertDontSee(route('admin.sales.orders.create'));
    }

    public function test_accountant_cannot_open_create_form(): void
    {
        $this->actingAs($this->withRole('accountant'))->get('/admin/sales/orders/create')->assertForbidden();
    }

    public function test_create_order_captures_city_area_and_computes_delivery_fee(): void
    {
        $gov = Governorate::firstOrCreate(['name' => 'فلسطين'], ['country_code' => 'PS', 'is_active' => true]);
        $city = City::create(['governorate_id' => $gov->id, 'name' => 'رام الله', 'is_active' => true]);
        $area = Area::create(['city_id' => $city->id, 'name' => 'المصيون', 'is_active' => true]);
        DeliveryCityRate::create(['city_id' => $city->id, 'name' => 'رام الله', 'delivery_fee' => 20, 'currency' => 'ILS', 'is_active' => true]);

        $variant = Product::factory()->create()->defaultVariant;

        $response = $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'محمد',
            'customer_phone' => '0599000000',
            'city_id' => $city->id,
            'area_id' => $area->id,
            'shipping_address' => 'شارع الإرسال',
            'has_return' => '1',
            'return_notes' => 'قطعة مرتجعة',
            'notes' => 'اتصل قبل التوصيل',
            'items' => [['variant' => $variant->uuid, 'qty' => 2, 'unit_price' => 100]],
        ]);

        $response->assertRedirect();

        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame($city->id, $order->city_id);
        $this->assertSame($area->id, $order->area_id);
        $this->assertTrue($order->has_return);
        $this->assertSame('قطعة مرتجعة', $order->return_notes);
        $this->assertEqualsWithDelta(20.0, (float) $order->shipping_total, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $order->subtotal, 0.001);
        $this->assertEqualsWithDelta(220.0, (float) $order->total, 0.001); // 200 + 20 توصيل
    }

    public function test_confirm_without_live_provider_just_confirms(): void
    {
        // بلا مزوّد مُفعّل (config الافتراضي 'null') ⇒ تأكيد فقط دون إرسال ولا مهمة.
        Queue::fake();
        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'سارة',
            'customer_phone' => '0599111111',
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $this->actingAs($this->admin())->post(route('admin.sales.orders.confirm', $order))->assertRedirect();

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->tracking_number);
        Queue::assertNothingPushed();
    }

    public function test_confirm_queues_delivery_dispatch_when_provider_configured(): void
    {
        // مزوّد مُفعّل ⇒ التأكيد يضع مهمة إرسال في الطابور (لا اتصال متزامن).
        config()->set('shipping.provider', 'opost');
        Queue::fake();

        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'خالد',
            'customer_phone' => '0599222222',
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $this->actingAs($this->admin())->post(route('admin.sales.orders.confirm', $order))->assertRedirect();

        $this->assertSame('confirmed', $order->fresh()->status);
        Queue::assertPushed(DispatchOrderShipment::class);
    }

    public function test_cancel_queues_provider_cancel_when_order_was_sent(): void
    {
        // طلب أُرسل لـOpost (له معرّف خارجي) ⇒ إلغاؤه يضع مهمة إلغاء لدى المزوّد.
        config()->set('shipping.provider', 'opost');
        Queue::fake();

        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'ليان',
            'customer_phone' => '0599333333',
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $order->update(['delivery_external_id' => '9999', 'tracking_number' => '9999']);

        $this->actingAs($this->admin())
            ->post(route('admin.sales.orders.cancel', $order), ['reason' => 'اختبار الإلغاء'])
            ->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        Queue::assertPushed(CancelOrderShipment::class);
    }

    public function test_delete_blocked_unless_order_and_delivery_cancelled(): void
    {
        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'نور', 'customer_phone' => '0599444444',
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();
        $order = Order::latest('id')->first(); // draft — غير قابل للحذف

        $this->actingAs($this->admin())->delete(route('admin.sales.orders.destroy', $order))->assertRedirect();

        $this->assertNull($order->fresh()->deleted_at); // لم يُحذف
    }

    public function test_delete_allowed_when_order_and_delivery_both_cancelled(): void
    {
        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'رامي', 'customer_phone' => '0599555555',
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();
        $order = Order::latest('id')->first();
        $order->update(['status' => 'cancelled']);
        Shipment::create([
            'number' => 'SHP-DEL-'.$order->id, 'order_id' => $order->id,
            'branch_id' => $order->branch_id, 'warehouse_id' => $order->warehouse_id,
            'status' => 'not_shipped', 'delivery_status' => DeliveryStatus::CANCELLED,
            'recipient_name' => 'x', 'recipient_phone' => '0599555555', 'external_id' => '999',
        ]);

        $this->actingAs($this->admin())->delete(route('admin.sales.orders.destroy', $order))->assertRedirect();

        $this->assertNotNull($order->fresh()->deleted_at); // حُذف (soft delete)
    }
}
