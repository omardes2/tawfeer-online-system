<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أزرار صفحة الطلب: «إرسال لشركة التوصيل» و«تسليم» محذوفان بطلب المالك.
 *
 * الحذف عرضٌ فقط — المساران والصلاحيتان باقيان، والإرسال التلقائي عند التأكيد
 * (ومكنسةُ إعادة المحاولة) لم يُمَسّا. الاختبار يحرس الأمرين معًا: ألّا يعود
 * الزرّان، وألّا يكون حذفهما قد كسر المسار البرمجي.
 */
class OrderPageButtonsTest extends TestCase
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

    private function order(string $status): Order
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 50);

        $order = Order::create([
            'number' => 'SO-'.fake()->unique()->numberBetween(100000, 999999),
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599000000',
            'channel' => 'manual', 'status' => $status,
            'confirmed_at' => in_array($status, ['draft', 'new'], true) ? null : now(),
            'subtotal' => 100, 'total' => 100,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $variant->id,
            'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);

        return $order->fresh();
    }

    public function test_the_manual_dispatch_button_is_gone(): void
    {
        // الحالة التي كان يظهر فيها: طلب توصيل مؤكَّد بلا رقم تتبّع.
        $order = $this->order('confirmed');

        $this->actingAs($this->admin())
            ->get(route('admin.sales.orders.show', $order))
            ->assertOk()
            ->assertDontSee(route('admin.sales.orders.resend_shipment', $order), false)
            ->assertDontSee('إرسال لشركة التوصيل', false);
    }

    public function test_the_deliver_button_is_gone(): void
    {
        $order = $this->order('shipped');

        $this->actingAs($this->admin())
            ->get(route('admin.sales.orders.show', $order))
            ->assertOk()
            ->assertDontSee(route('admin.sales.orders.deliver', $order), false);
    }

    public function test_the_remaining_actions_still_render(): void
    {
        // شبكة أمان: الحذف لم يبتلع بقيّة أزرار الصفحة.
        $order = $this->order('confirmed');

        $this->actingAs($this->admin())
            ->get(route('admin.sales.orders.show', $order))
            ->assertOk()
            ->assertSee(route('admin.sales.orders.invoice', $order), false)
            ->assertSee(route('admin.sales.orders.cancel', $order), false);
    }

    public function test_the_routes_themselves_still_work(): void
    {
        // حُذف الزرّ لا القدرة: المسار يبقى صالحًا للاستخدام البرمجي.
        $order = $this->order('shipped');

        $this->actingAs($this->admin())
            ->post(route('admin.sales.orders.deliver', $order))
            ->assertRedirect();

        $this->assertSame('delivered', $order->fresh()->status);
    }
}
