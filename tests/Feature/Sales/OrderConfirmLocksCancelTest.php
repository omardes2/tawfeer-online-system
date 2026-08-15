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
 * التأكيد يقفل الإلغاء في وجه مُدخِل الطلب.
 *
 * قبل التأكيد الطلب مسوّدة عند موظف المبيعات/المسوّق، وإلغاؤه تصحيحُ خطأ لا أثر
 * له. بعد التأكيد يكون قد رُحِّل محاسبيًّا وأُرسل لشركة التوصيل، فالإلغاء يعكس
 * قيودًا ومخزونًا ويُلغي شحنة قائمة — قرارٌ يخصّ من يملك التأكيد نفسه.
 *
 * الاختبارات تطرق المسار لا الزرّ: إخفاء الزرّ عرضٌ، والمنع في السياسة.
 */
class OrderConfirmLocksCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function order(string $status = 'draft', ?User $creator = null): Order
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
            'created_by' => $creator?->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $variant->id,
            'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);

        return $order;
    }

    private function cancel(User $user, Order $order): TestResponse
    {
        return $this->actingAs($user)->post(route('admin.sales.orders.cancel', $order), ['reason' => 'اختبار']);
    }

    /** الدوران بدل مزوّد بيانات: التعليقات التوضيحية غير مفعّلة في هذا الإعداد. */
    private const CREATOR_ROLES = ['sales', 'affiliate'];

    public function test_a_creator_may_still_cancel_before_confirmation(): void
    {
        foreach (self::CREATOR_ROLES as $role) {
            $user = $this->withRole($role);
            $order = $this->order('draft', $user);

            $this->assertTrue($user->can('cancel', $order), $role);
            $this->cancel($user, $order)->assertRedirect();
            $this->assertSame('cancelled', $order->fresh()->status, $role);
        }
    }

    public function test_a_creator_cannot_cancel_once_confirmed(): void
    {
        foreach (self::CREATOR_ROLES as $role) {
            $user = $this->withRole($role);
            $order = $this->order('confirmed', $user);

            $this->assertFalse($user->can('cancel', $order), $role);
            $this->cancel($user, $order)->assertForbidden();
            $this->assertSame('confirmed', $order->fresh()->status, $role);
        }
    }

    public function test_the_cancel_button_disappears_from_their_page_after_confirmation(): void
    {
        $user = $this->withRole('sales');
        $draft = $this->order('draft', $user);

        $this->actingAs($user)->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee(route('admin.sales.orders.cancel', $draft), false);

        $draft->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        $this->actingAs($user)->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertDontSee(route('admin.sales.orders.cancel', $draft), false);
    }

    public function test_the_manager_keeps_cancelling_after_confirmation(): void
    {
        // الإلغاء لا يُلغى من النظام — ينتقل إلى من يملك التأكيد.
        $order = $this->order('confirmed');

        $this->assertTrue($this->admin()->can('cancel', $order));
        $this->cancel($this->admin(), $order)->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
