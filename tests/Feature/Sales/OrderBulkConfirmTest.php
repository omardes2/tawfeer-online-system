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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * زرّ «تأكيد» بجانب البحث: تأكيد الطلبيات المحدَّدة دفعةً واحدة.
 *
 * لا منطق تأكيد جديد — كل طلب يمرّ بنفس مسار الزرّ المفرد. وبلا معاملة جامعة
 * عمدًا: التأكيد يُرسل لطرف خارجي، وتراجعُ معاملةٍ بعد إرسال شحنة يترك النظام
 * يخالف الواقع. فكل طلب يقف بنفسه، والتقرير يذكر كم نجح وكم تُخطّي.
 */
class OrderBulkConfirmTest extends TestCase
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
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function order(string $status = 'draft'): Order
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

        return $order;
    }

    private function bulkConfirm(User $user, array $ids): TestResponse
    {
        return $this->actingAs($user)->post(route('admin.sales.orders.bulk_confirm'), ['ids' => $ids]);
    }

    public function test_selected_orders_are_confirmed_together(): void
    {
        $a = $this->order();
        $b = $this->order();
        $untouched = $this->order();

        $this->bulkConfirm($this->admin(), [$a->id, $b->id])->assertRedirect();

        $this->assertSame('confirmed', $a->fresh()->status);
        $this->assertSame('confirmed', $b->fresh()->status);
        $this->assertNotNull($a->fresh()->confirmed_at);
        $this->assertSame('draft', $untouched->fresh()->status);
    }

    public function test_an_already_confirmed_order_is_skipped_without_failing_the_batch(): void
    {
        $fresh = $this->order();
        $already = $this->order('confirmed');

        $this->bulkConfirm($this->admin(), [$fresh->id, $already->id])->assertRedirect();

        // الدفعة لا تسقط بسبب طلب واحد لا يقبل التأكيد.
        $this->assertSame('confirmed', $fresh->fresh()->status);
    }

    public function test_an_empty_selection_reports_instead_of_crashing(): void
    {
        $this->bulkConfirm($this->admin(), [])->assertRedirect()->assertSessionHas('error');
    }

    public function test_sales_and_marketers_cannot_bulk_confirm(): void
    {
        $order = $this->order();

        foreach (['sales', 'affiliate'] as $role) {
            $this->bulkConfirm($this->withRole($role), [$order->id])->assertForbidden();
            $this->assertSame('draft', $order->fresh()->status, $role);
        }
    }

    public function test_the_confirm_button_shows_only_for_those_who_may_confirm(): void
    {
        $this->order();

        $this->actingAs($this->admin())->get(route('admin.sales.orders.index'))
            ->assertOk()->assertSee(route('admin.sales.orders.bulk_confirm'), false);

        $this->actingAs($this->withRole('sales'))->get(route('admin.sales.orders.index'))
            ->assertOk()->assertDontSee(route('admin.sales.orders.bulk_confirm'), false);
    }

    public function test_the_select_all_box_is_available_to_the_manager(): void
    {
        $this->order();

        // «تحديد كل الطلبيات» — عمود التحديد يظهر لمن يملك تأكيدًا أو حذفًا.
        $this->actingAs($this->admin())->get(route('admin.sales.orders.index'))
            ->assertOk()->assertSee(__('تحديد كل الطلبيات'), false);
    }

    public function test_bulk_confirm_leaves_the_order_cancellable_by_the_manager_only(): void
    {
        $order = $this->order();
        $seller = $this->withRole('sales');

        $this->assertTrue($seller->can('cancel', $order));

        $this->bulkConfirm($this->admin(), [$order->id])->assertRedirect();

        // جوهر الميزة: التأكيد الجماعي يقفل الإلغاء عن مُدخِل الطلب.
        $this->assertFalse($seller->can('cancel', $order->fresh()));
        $this->assertTrue($this->admin()->can('cancel', $order->fresh()));
    }
}
