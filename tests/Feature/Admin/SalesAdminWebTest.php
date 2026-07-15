<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Support\DeliveryStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTrackingDeliveryProvider;
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

    /** مدينة + منطقة صالحتان (التوصيل إجباري على شاشة الإدارة). */
    private function geo(): array
    {
        $gov = Governorate::firstOrCreate(['name' => 'فلسطين'], ['country_code' => 'PS', 'is_active' => true]);
        $city = City::firstOrCreate(['governorate_id' => $gov->id, 'name' => 'رام الله'], ['is_active' => true]);
        $area = Area::firstOrCreate(['city_id' => $city->id, 'name' => 'المصيون'], ['is_active' => true]);

        return ['city_id' => $city->id, 'area_id' => $area->id, 'shipping_address' => 'شارع رام الله'];
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

    public function test_create_order_requires_name_phone_city_area_and_item(): void
    {
        // حقول مفقودة ⇒ أخطاء تحقّق لكل حقل (لا يُنشأ طلب).
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'items' => [],
        ])->assertSessionHasErrors(['customer_name', 'customer_phone', 'city_id', 'area_id', 'items']);
    }

    public function test_admin_order_requires_valid_phone_and_address(): void
    {
        $variant = Product::factory()->create()->defaultVariant;

        // هاتف قصير + بلا عنوان ⇒ أخطاء تحقّق (لا يُنشأ طلب يفشل لاحقًا في Opost).
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'خالد',
            'customer_phone' => '12345',
            'city_id' => $this->geo()['city_id'],
            'area_id' => $this->geo()['area_id'],
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertSessionHasErrors(['customer_phone', 'shipping_address']);
    }

    public function test_admin_order_normalizes_phone_to_ten_digits(): void
    {
        Queue::fake();
        $variant = Product::factory()->create()->defaultVariant;

        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'ليان',
            'customer_phone' => '+970 59 900 1122', // مقدّمة دولة + فراغات
            ...$this->geo(),
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('0599001122', Order::latest('id')->first()->customer_phone);
    }

    public function test_receive_return_restocks_only_when_returned_with_driver(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'راجع', 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->id, 'qty' => 3, 'unit_price' => 10]], 2026);

        $provider = DeliveryProvider::firstOrCreate(['code' => 'opost'], ['name' => 'Opost', 'driver' => 'opost']);
        Shipment::create([
            'number' => 'SHP-'.$order->id, 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id, 'status' => 'not_shipped',
            'recipient_name' => 'راجع', 'recipient_phone' => '0599000000',
            'delivery_provider_id' => $provider->id, 'provider_status' => 'delivered', // مرتجع مع السائق
        ]);

        $stock = fn () => (float) (InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand') ?? 0);
        $before = $stock();

        $this->actingAs($this->admin())->post(route('admin.sales.orders.receive_return', $order))->assertRedirect();

        $this->assertEqualsWithDelta($before + 3, $stock(), 0.001);
        $this->assertNotNull($order->fresh()->return_received_at);

        // idempotent — نداء ثانٍ لا يزيد المخزون مجددًا.
        $this->actingAs($this->admin())->post(route('admin.sales.orders.receive_return', $order));
        $this->assertEqualsWithDelta($before + 3, $stock(), 0.001);
    }

    public function test_receive_return_blocked_when_status_not_returned_with_driver(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'ع', 'customer_phone' => '0599000000',
        ], [], 2026);
        $provider = DeliveryProvider::firstOrCreate(['code' => 'opost'], ['name' => 'Opost', 'driver' => 'opost']);
        Shipment::create([
            'number' => 'SHP2-'.$order->id, 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id, 'status' => 'not_shipped',
            'recipient_name' => 'ع', 'recipient_phone' => '0599000000',
            'delivery_provider_id' => $provider->id, 'provider_status' => 'picked_up',
        ]);

        $this->actingAs($this->admin())->post(route('admin.sales.orders.receive_return', $order))
            ->assertSessionHas('error');
        $this->assertNull($order->fresh()->return_received_at);
    }

    public function test_cancel_awaiting_pickup_order_cancels_provider_shipment_synchronously(): void
    {
        config()->set('shipping.provider', 'faketrack');
        config()->set('shipping.drivers.faketrack.delivery', FakeTrackingDeliveryProvider::class);
        FakeTrackingDeliveryProvider::$cancelResult = true;
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'س', 'customer_phone' => '0599000000',
        ], [], 2026);
        // أُرسل للمزوّد (له رقم تتبّع) وحالته أوبتيموس «بانتظار الاستلام».
        $order->update(['status' => 'confirmed', 'tracking_number' => 'TRK-1', 'delivery_external_id' => 'TRK-1']);
        $provider = DeliveryProvider::firstOrCreate(['code' => 'faketrack'], ['name' => 'Fake', 'driver' => 'faketrack']);
        Shipment::create([
            'number' => 'SHPX-'.$order->id, 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id, 'status' => 'not_shipped',
            'recipient_name' => 'س', 'recipient_phone' => '0599000000',
            'delivery_provider_id' => $provider->id, 'external_id' => 'TRK-1', 'provider_status' => 'submitted',
        ]);

        $this->actingAs($this->admin())->post(route('admin.sales.orders.cancel', $order), [
            'reason' => 'إلغاء قبل الاستلام',
        ])->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', Shipment::where('order_id', $order->id)->value('delivery_status'));
    }

    public function test_direct_sale_form_renders(): void
    {
        $this->actingAs($this->admin())->get(route('admin.sales.orders.direct.create'))
            ->assertOk()->assertSee('مبيعات مباشرة');
    }

    public function test_direct_sale_fulfills_and_deducts_stock_without_shipment(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 5);

        $this->actingAs($this->admin())->post(route('admin.sales.orders.direct.store'), [
            'customer_name' => 'زبون مباشر',
            'items' => [['variant' => $variant->uuid, 'qty' => 3, 'unit_price' => 20]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $this->assertSame('pos', $order->channel);
        $this->assertSame('delivered', $order->status);       // مُسلَّم فورًا
        $this->assertEquals(0, $order->shipments()->count());  // بلا شحنة/توصيل

        $onHand = (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');
        $this->assertEqualsWithDelta(7, $onHand, 0.001);       // 10 − 3
    }

    public function test_direct_sale_with_registered_customer_links_customer(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 5, 5);

        $customer = Customer::create([
            'branch_id' => Branch::default()->id, 'name' => 'عميل مسجّل', 'primary_phone' => '0599777888',
        ]);

        $this->actingAs($this->admin())->post(route('admin.sales.orders.direct.store'), [
            'customer' => $customer->uuid,
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 20]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::latest('id')->first();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('عميل مسجّل', $order->customer_name);
        $this->assertSame('0599777888', $order->customer_phone);
    }

    public function test_orders_search_by_recipient_and_tracking(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $a = app(OrderService::class)->create(['branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id, 'customer_name' => 'سالم المطر', 'customer_phone' => '0599000000'], [], 2026);
        $a->update(['tracking_number' => 'TRK-9911']);
        app(OrderService::class)->create(['branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id, 'customer_name' => 'ليلى', 'customer_phone' => '0599000001'], [], 2026);

        // بحث بالاسم
        $this->actingAs($this->admin())->get('/admin/sales/orders?search='.urlencode('سالم'))
            ->assertOk()->assertSee('سالم المطر')->assertDontSee('ليلى');

        // بحث برقم التتبّع
        $this->actingAs($this->admin())->get('/admin/sales/orders?search=TRK-9911')
            ->assertOk()->assertSee('سالم المطر')->assertDontSee('ليلى');
    }

    public function test_orders_filter_by_sale_type(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 5);

        // بيع مباشر (channel=pos)
        $this->actingAs($this->admin())->post(route('admin.sales.orders.direct.store'), [
            'customer_name' => 'زبون مباشر فريد', 'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 20]],
        ])->assertRedirect();
        // طلب عادي
        app(OrderService::class)->create(['branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id, 'customer_name' => 'زبون عادي فريد', 'customer_phone' => '0599000000'], [], 2026);

        $this->actingAs($this->admin())->get('/admin/sales/orders?sale_type=direct')
            ->assertOk()->assertSee('زبون مباشر فريد')->assertDontSee('زبون عادي فريد');
        $this->actingAs($this->admin())->get('/admin/sales/orders?sale_type=normal')
            ->assertOk()->assertSee('زبون عادي فريد')->assertDontSee('زبون مباشر فريد');
    }

    public function test_settle_marks_direct_sale_paid(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 5);

        $this->actingAs($this->admin())->post(route('admin.sales.orders.direct.store'), [
            'customer_name' => 'زبون نقدي', 'items' => [['variant' => $variant->uuid, 'qty' => 2, 'unit_price' => 30]],
        ])->assertRedirect();

        $order = Order::where('channel', 'pos')->latest('id')->firstOrFail();
        $this->assertNotSame('paid', $order->payment_status);

        $treasury = Treasury::where('code', 'CB-MAIN')->firstOrFail();
        $this->actingAs($this->admin())->post(route('admin.sales.orders.settle', $order), [
            'amount' => (float) $order->total,
            'treasury_id' => $treasury->id,
        ])->assertRedirect()->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta((float) $order->total, (float) $order->amount_paid, 0.01);
        // سند قبض مُرحّل على الخزينة مرتبط بالطلب.
        $this->assertSame(1, FinancialVoucher::where('kind', 'receipt')
            ->where('reference', $order->number)->where('status', 'posted')->count());
    }

    public function test_settle_partial_then_full_marks_paid(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 5);

        $this->actingAs($this->admin())->post(route('admin.sales.orders.direct.store'), [
            'customer_name' => 'زبون جزئي', 'items' => [['variant' => $variant->uuid, 'qty' => 2, 'unit_price' => 30]],
        ])->assertRedirect();

        $order = Order::where('channel', 'pos')->latest('id')->firstOrFail();
        $treasury = Treasury::where('code', 'CB-MAIN')->firstOrFail();

        // دفعة جزئية (نصف المبلغ) → partially_paid
        $this->actingAs($this->admin())->post(route('admin.sales.orders.settle', $order), [
            'amount' => 30, 'treasury_id' => $treasury->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('partially_paid', $order->fresh()->payment_status);

        // بقية المبلغ → paid
        $this->actingAs($this->admin())->post(route('admin.sales.orders.settle', $order), [
            'amount' => 30, 'treasury_id' => $treasury->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_settle_rejected_for_normal_sale(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'عادي', 'customer_phone' => '0599000000',
        ], [], 2026); // channel افتراضي ليس pos

        $this->actingAs($this->admin())->post(route('admin.sales.orders.settle', $order))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_direct_sale_shows_pay_button_normal_sale_does_not(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 5);

        $this->actingAs($this->admin())->post(route('admin.sales.orders.direct.store'), [
            'customer_name' => 'زبون زر الدفع', 'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 15]],
        ])->assertRedirect();
        $order = Order::where('channel', 'pos')->latest('id')->firstOrFail();

        $this->actingAs($this->admin())->get('/admin/sales/orders')
            ->assertOk()
            ->assertSee(route('admin.sales.orders.settle', $order), false);
    }

    public function test_confirm_without_live_provider_just_confirms(): void
    {
        // بلا مزوّد مُفعّل ⇒ تأكيد فقط دون إرسال ولا مهمة. نضبط القيمة صراحةً
        // حتى لا يعتمد الاختبار على ترتيب التنفيذ (تفادي تسرّب config من اختبار آخر).
        config()->set('shipping.provider', 'null');
        Queue::fake();
        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'سارة',
            'customer_phone' => '0599111111',
            ...$this->geo(),
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $this->actingAs($this->admin())->post(route('admin.sales.orders.confirm', $order))->assertRedirect();

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->tracking_number);
        Queue::assertNothingPushed();
    }

    public function test_confirm_dispatches_delivery_synchronously_when_provider_configured(): void
    {
        // الترحيل الجذري: مزوّد مُفعّل ⇒ التأكيد يُرسل الطلب لشركة التوصيل مباشرةً (متزامن)
        // فيظهر رقم التتبّع فورًا — لا اعتماد على طابور.
        config()->set('shipping.provider', 'faketrack');
        config()->set('shipping.drivers.faketrack.delivery', FakeTrackingDeliveryProvider::class);
        FakeTrackingDeliveryProvider::$createResult = null;

        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'خالد',
            'customer_phone' => '0599222222',
            ...$this->geo(),
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $this->actingAs($this->admin())->post(route('admin.sales.orders.confirm', $order))->assertRedirect();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotEmpty($order->tracking_number); // أُرسل مباشرةً لشركة التوصيل.
    }

    public function test_cancel_cancels_provider_shipment_synchronously(): void
    {
        // طلب أُرسل للمزوّد (له معرّف خارجي وشحنة نشطة) ⇒ إلغاؤه يُلغي الشحنة لدى المزوّد
        // مباشرةً (متزامن) وينعكس محليًا — لا اعتماد على طابور.
        config()->set('shipping.provider', 'faketrack');
        config()->set('shipping.drivers.faketrack.delivery', FakeTrackingDeliveryProvider::class);
        FakeTrackingDeliveryProvider::$cancelResult = true;

        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'ليان',
            'customer_phone' => '0599333333',
            ...$this->geo(),
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertRedirect();

        $order = Order::latest('id')->first();
        $order->update(['delivery_external_id' => '9999', 'tracking_number' => '9999']);
        $provider = DeliveryProvider::firstOrCreate(['code' => 'faketrack'], ['name' => 'Fake', 'driver' => 'faketrack']);
        Shipment::create([
            'number' => 'SHP-'.$order->id, 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id, 'status' => 'not_shipped',
            'recipient_name' => 'ليان', 'recipient_phone' => '0599333333',
            'delivery_provider_id' => $provider->id, 'external_id' => '9999', 'provider_status' => 'submitted',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.sales.orders.cancel', $order), ['reason' => 'اختبار الإلغاء'])
            ->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        // أُلغيت الشحنة لدى المزوّد وانعكست محليًا (لم تبقَ نشطة).
        $this->assertSame('cancelled', $order->fresh()->delivery_status);
        $this->assertSame('cancelled', Shipment::where('order_id', $order->id)->value('delivery_status'));
    }

    public function test_delete_blocked_unless_order_and_delivery_cancelled(): void
    {
        $variant = Product::factory()->create()->defaultVariant;
        $this->actingAs($this->admin())->post('/admin/sales/orders', [
            'customer_name' => 'نور', 'customer_phone' => '0599444444', ...$this->geo(),
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
            'customer_name' => 'رامي', 'customer_phone' => '0599555555', ...$this->geo(),
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
