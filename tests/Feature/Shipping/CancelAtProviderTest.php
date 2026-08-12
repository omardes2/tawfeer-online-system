<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\OrderVoidService;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use App\Modules\Shipping\Support\OpostStatus;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use App\Support\Integrations\Shipping\DeliveryProviderManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * إلغاء الطلب يجب أن يُلغي الطرد لدى شركة التوصيل — وإن تعذّر، **يُسجَّل** الفشل ليظهر
 * للمستخدم وتعيد المكنسة المحاولة. ابتلاعه صمتًا كان يُبقي الطرد نشطًا فيصل العميل
 * بضاعةً لطلب ملغى.
 */
class CancelAtProviderTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        config(['shipping.provider' => 'opost']);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('admin');

        return $u;
    }

    /** طلب مؤكّد أُرسل للمزوّد (له رقم تتبّع). */
    private function dispatchedOrder(): Order
    {
        $product = Product::factory()->active()->create(['retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 10, 60);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 1, 'unit_price' => 100, 'discount' => 0]], 2026);

        app(OrderService::class)->confirm($order);
        $order->update(['tracking_number' => '7432283', 'delivery_external_id' => '7432283']);

        return $order->fresh();
    }

    /** يزيّف الـDispatcher بنتيجة إلغاء محدّدة. */
    private function fakeDispatcher(array $result): void
    {
        $mock = Mockery::mock(OrderDeliveryDispatcher::class);
        $mock->shouldReceive('cancelShipment')->andReturn($result);
        $mock->shouldReceive('dispatch')->andReturn(['status' => 'skipped']);
        $this->app->instance(OrderDeliveryDispatcher::class, $mock);
    }

    public function test_failed_cancellation_is_recorded_on_the_order(): void
    {
        $order = $this->dispatchedOrder();
        $this->fakeDispatcher(['status' => 'failed', 'message' => 'المزوّد لا يستجيب']);

        app(OrderVoidService::class)->cancelWithReversal($order, $this->admin(), 'إلغاء');

        $order->refresh();
        $this->assertSame('المزوّد لا يستجيب', $order->delivery_cancel_error);
        $this->assertNotNull($order->delivery_cancel_attempted_at);
        $this->assertSame('cancelled', $order->status); // الإلغاء المحاسبي تمّ رغم فشل المزوّد
    }

    public function test_successful_cancellation_leaves_no_error(): void
    {
        $order = $this->dispatchedOrder();
        $this->fakeDispatcher(['status' => 'cancelled']);

        app(OrderVoidService::class)->cancelWithReversal($order, $this->admin(), 'إلغاء');

        $this->assertNull($order->fresh()->delivery_cancel_error);
    }

    /** المكنسة تعيد المحاولة وتمسح الأثر عند النجاح. */
    public function test_sweeper_retries_and_clears_on_success(): void
    {
        $order = $this->dispatchedOrder();
        $this->fakeDispatcher(['status' => 'failed', 'message' => 'انقطاع']);
        app(OrderVoidService::class)->cancelWithReversal($order, $this->admin(), 'إلغاء');
        $this->assertNotNull($order->fresh()->delivery_cancel_error);

        // المزوّد عاد للعمل ⇒ التمريرة التالية تنجح.
        $this->fakeDispatcher(['status' => 'cancelled']);
        $this->artisan('shipping:cancel-pending')->assertSuccessful();

        $this->assertNull($order->fresh()->delivery_cancel_error);
    }

    /** الطلب المحذوف إداريًا يبقى ضمن نطاق المكنسة (طرده يجب أن يُلغى أيضًا). */
    public function test_sweeper_covers_soft_deleted_orders(): void
    {
        $order = $this->dispatchedOrder();
        $this->fakeDispatcher(['status' => 'failed', 'message' => 'انقطاع']);
        app(OrderVoidService::class)->void($order, $this->admin());

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertNotNull(Order::withTrashed()->find($order->id)->delivery_cancel_error);

        $this->fakeDispatcher(['status' => 'cancelled']);
        $this->artisan('shipping:cancel-pending')->assertSuccessful();

        $this->assertNull(Order::withTrashed()->find($order->id)->delivery_cancel_error);
    }

    /**
     * بعد نجاح الإلغاء لدى المزوّد، عمود «حالة أوبتيموس» يعرض «ملغى» لا الحالة القديمة —
     * كان يبقى «بانتظار الاستلام» لأن provider_status لم يُحدَّث.
     */
    public function test_successful_cancellation_marks_provider_status_cancelled(): void
    {
        $order = $this->dispatchedOrder();
        $shipment = Shipment::create([
            'number' => 'SHP-C-'.$order->id,
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'status' => 'not_shipped',
            'recipient_name' => 'x', 'recipient_phone' => '0500000000',
            'external_id' => '7432406',
            'provider_status' => 'pending_pickup',
            'delivery_status' => 'ready_for_pickup',
        ]);

        // مزوّد حقيقي يُرجع نجاحًا (لا نزيّف الـDispatcher كي يُنفَّذ تحديث اللقطة).
        $driver = Mockery::mock(DeliveryProviderInterface::class);
        $driver->shouldReceive('cancel')->andReturn(true);
        $manager = Mockery::mock(DeliveryProviderManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(DeliveryProviderManager::class, $manager);

        app(OrderVoidService::class)->cancelWithReversal($order, $this->admin(), 'إلغاء');

        $this->assertSame('cancelled', $shipment->fresh()->provider_status);
        $this->assertSame('cancelled', $shipment->fresh()->delivery_status);

        // العمود يقرأ provider_status لأحدث شحنة ⇒ يعرض «ملغى» بدل الحالة القديمة.
        // (التحقّق على المصدر لا على نصّ الصفحة: قائمة فلتر الحالات تذكر كل الحالات.)
        $this->assertSame('ملغى', OpostStatus::label(
            $order->fresh()->latestShipment->provider_status,
        ));
    }

    /** تنبيه مرئي في قائمة الطلبات ما دام الطرد نشطًا لدى المزوّد. */
    public function test_orders_list_warns_about_uncancelled_parcel(): void
    {
        $order = $this->dispatchedOrder();
        $this->fakeDispatcher(['status' => 'failed', 'message' => 'انقطاع']);
        app(OrderVoidService::class)->cancelWithReversal($order, $this->admin(), 'إلغاء');

        $this->actingAs($this->admin())->get(route('admin.sales.orders.index'))
            ->assertOk()->assertSee(__('لم تُلغَ لدى التوصيل'));
    }
}
