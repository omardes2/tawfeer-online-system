<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Policies\OrderPolicy;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Support\OpostStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * إلغاء الطلب ما دام الطرد «بانتظار الاستلام».
 *
 * الحدّ هو واقع الطرد لا حالة الطلب عندنا: قبل أن يستلمه المندوب لم يتحرّك شيء،
 * فإلغاؤه تصحيحٌ نظيف يقع عندنا وعند شركة التوصيل معًا. وبعد الاستلام يصير في
 * الطريق فعلًا فينتقل القرار إلى من يملك التأكيد.
 *
 * الاختبارات تطرق المسار لا الزرّ: إخفاء الزرّ عرضٌ، والمنع في السياسة.
 */
class OrderCancelWhileAwaitingPickupTest extends TestCase
{
    use RefreshDatabase;

    private const CREATOR_ROLES = ['sales', 'affiliate'];

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

    /** طلب مؤكَّد ومُرسَل لأوبتيموس، بحالة طردٍ محدَّدة. */
    private function dispatchedOrder(string $providerStatus, ?User $creator = null): Order
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 50);

        $order = Order::create([
            'number' => 'SO-'.fake()->unique()->numberBetween(100000, 999999),
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599000000',
            'channel' => 'manual', 'status' => 'confirmed', 'confirmed_at' => now(),
            'tracking_number' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'subtotal' => 100, 'total' => 100,
            'created_by' => $creator?->id, 'assigned_to' => $creator?->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $variant->id,
            'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);

        Shipment::create([
            'number' => 'SH-'.fake()->unique()->numberBetween(100000, 999999),
            'order_id' => $order->id,
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'created',
            'provider_status' => $providerStatus,
            'recipient_name' => 'زبون',
            'recipient_phone' => '0599000000',
            'tracking_number' => $order->tracking_number,
        ]);

        return $order->fresh();
    }

    private function cancel(User $user, Order $order): TestResponse
    {
        return $this->actingAs($user)->post(route('admin.sales.orders.cancel', $order), ['reason' => 'تراجع الزبون']);
    }

    public function test_the_policy_list_matches_the_provider_labels(): void
    {
        // حارس ضدّ الانحراف: لو أضاف أوبتيموس حالةً قبل الاستلام أو غُيّرت
        // تسمية، تنكشف هنا بدل أن تفتح الإلغاء أو تغلقه صامتًا.
        $labelled = array_keys(array_filter(
            OpostStatus::LABELS,
            fn (string $label) => $label === 'بانتظار الاستلام',
        ));

        sort($labelled);
        $policy = OrderPolicy::AWAITING_PICKUP_STATUSES;
        sort($policy);

        $this->assertSame($labelled, $policy);
    }

    public function test_a_seller_may_cancel_while_the_parcel_awaits_pickup(): void
    {
        foreach (self::CREATOR_ROLES as $role) {
            foreach (OrderPolicy::AWAITING_PICKUP_STATUSES as $status) {
                $user = $this->withRole($role);
                $order = $this->dispatchedOrder($status, $user);

                $this->assertTrue($user->can('cancel', $order), "$role/$status");
                $this->cancel($user, $order)->assertRedirect();
                $this->assertSame('cancelled', $order->fresh()->status, "$role/$status");
            }
        }
    }

    public function test_a_seller_cannot_cancel_once_the_courier_took_it(): void
    {
        foreach (self::CREATOR_ROLES as $role) {
            $user = $this->withRole($role);
            $order = $this->dispatchedOrder('picked_up', $user);

            $this->assertFalse($user->can('cancel', $order), $role);
            $this->cancel($user, $order)->assertForbidden();
            $this->assertSame('confirmed', $order->fresh()->status, $role);
        }
    }

    public function test_the_button_follows_the_parcel_status_on_their_page(): void
    {
        $user = $this->withRole('sales');
        $waiting = $this->dispatchedOrder('submitted', $user);

        $this->actingAs($user)->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee(route('admin.sales.orders.cancel', $waiting), false);

        $waiting->latestShipment->update(['provider_status' => 'picked_up']);

        $this->actingAs($user)->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertDontSee(route('admin.sales.orders.cancel', $waiting), false);
    }

    public function test_cancelling_also_cancels_at_the_delivery_company(): void
    {
        // مسار الإلغاء القائم هو من يخاطب المزوّد — لم يُبنَ هنا شيء جديد.
        // بلا مزوّد مفعّل في الاختبارات يبقى الأثر داخليًّا، والمهم أن الطلب
        // يمرّ بالمسار نفسه ويصل إلى «ملغى» بلا خطأ.
        $user = $this->withRole('sales');
        $order = $this->dispatchedOrder('submitted', $user);

        $this->cancel($user, $order)->assertRedirect()->assertSessionMissing('error');
        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
