<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Shipping\Models\Shipment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * زرّ «تأكيد»: اعتماد المدير للطلبات المحدَّدة.
 *
 * الاعتماد غير التأكيد الداخلي: الطلب الذي طردُه «بانتظار الاستلام» مؤكَّدٌ
 * سلفًا ومُرسَل لأوبتيموس، وما ينقصه مراجعةُ المدير التي **تُغلق الإلغاء** في
 * وجه مُدخِله. أمّا المسوّدة فتُؤكَّد وتُرسَل أولًا ثم تُعتمَد.
 */
class OrderManagerApprovalTest extends TestCase
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

    /** طلب بحالة طردٍ محدَّدة (null = مسوّدة لم تُرسَل بعد). */
    private function order(?string $providerStatus, ?User $creator = null): Order
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 50);

        $isDraft = $providerStatus === null;

        $order = Order::create([
            'number' => 'SO-'.fake()->unique()->numberBetween(100000, 999999),
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599000000',
            'channel' => 'manual',
            'status' => $isDraft ? 'draft' : 'confirmed',
            'confirmed_at' => $isDraft ? null : now(),
            'subtotal' => 100, 'total' => 100,
            'created_by' => $creator?->id, 'assigned_to' => $creator?->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $variant->id,
            'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);

        if (! $isDraft) {
            Shipment::create([
                'number' => 'SH-'.fake()->unique()->numberBetween(100000, 999999),
                'order_id' => $order->id,
                'branch_id' => Branch::default()->id,
                'warehouse_id' => $warehouse->id,
                'status' => 'created',
                'provider_status' => $providerStatus,
                'recipient_name' => 'زبون', 'recipient_phone' => '0599000000',
            ]);
        }

        return $order->fresh();
    }

    private function approve(User $user, array $ids): TestResponse
    {
        return $this->actingAs($user)->post(route('admin.sales.orders.bulk_confirm'), ['ids' => $ids]);
    }

    public function test_approving_an_awaiting_pickup_order_locks_the_sellers_cancel(): void
    {
        $seller = $this->withRole('sales');
        $order = $this->order('submitted', $seller);

        // قبل الاعتماد: البائع يُلغي (النافذة مفتوحة ما دام الطرد بانتظار الاستلام).
        $this->assertTrue($seller->can('cancel', $order));

        $this->approve($this->admin(), [$order->id])->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->approved_at);
        $this->assertSame($this->admin()->id, $order->approved_by);
        // بعد الاعتماد: أُغلقت النافذة رغم أن الطرد ما زال بانتظار الاستلام.
        $this->assertFalse($seller->can('cancel', $order));
        $this->assertTrue($this->admin()->can('cancel', $order));
    }

    public function test_the_order_is_not_re_confirmed_nor_re_sent(): void
    {
        $order = $this->order('submitted');
        $confirmedAt = $order->confirmed_at;

        $this->approve($this->admin(), [$order->id])->assertRedirect();

        // مؤكَّد سلفًا: لا يُعاد ترحيله ولا إرساله لشركة التوصيل.
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertEquals($confirmedAt->timestamp, $order->fresh()->confirmed_at->timestamp);
    }

    public function test_a_draft_is_confirmed_then_approved(): void
    {
        $draft = $this->order(null);

        $this->approve($this->admin(), [$draft->id])->assertRedirect();

        $draft->refresh();
        $this->assertSame('confirmed', $draft->status);
        $this->assertNotNull($draft->confirmed_at);
        $this->assertNotNull($draft->approved_at);
    }

    public function test_an_order_the_courier_already_took_is_not_approvable(): void
    {
        // بعد الاستلام لا نافذة تُغلَق — الإلغاء محصور بالمدير أصلًا.
        $order = $this->order('picked_up');

        $this->assertFalse($this->admin()->can('approve', $order));
        $this->approve($this->admin(), [$order->id])->assertRedirect()->assertSessionHas('error');
        $this->assertNull($order->fresh()->approved_at);
    }

    public function test_an_already_approved_order_is_not_approved_twice(): void
    {
        $order = $this->order('submitted');
        $this->approve($this->admin(), [$order->id])->assertRedirect();
        $stamp = $order->fresh()->approved_at;

        $this->approve($this->admin(), [$order->id])->assertRedirect()->assertSessionHas('error');
        $this->assertEquals($stamp->timestamp, $order->fresh()->approved_at->timestamp);
    }

    public function test_sellers_cannot_approve(): void
    {
        $order = $this->order('submitted');

        foreach (['sales', 'affiliate'] as $role) {
            $this->approve($this->withRole($role), [$order->id])->assertForbidden();
            $this->assertNull($order->fresh()->approved_at, $role);
        }
    }

    public function test_awaiting_pickup_rows_are_selectable_for_the_manager(): void
    {
        // جوهر البلاغ: كانت الصناديق معطّلة كلّها فلا «تحديد الكل» ولا «تأكيد».
        $order = $this->order('submitted');

        $html = $this->actingAs($this->admin())->get(route('admin.sales.orders.index'))
            ->assertOk()->getContent();

        $at = strpos($html, 'value="'.$order->id.'"');
        $this->assertNotFalse($at, 'صندوق تحديد الطلب غير موجود.');

        $tag = substr($html, strrpos(substr($html, 0, $at), '<input'), 400);
        $tag = substr($tag, 0, strpos($tag, '>'));
        // تُنزع السمة `class` أولًا: أصناف Tailwind تحوي `disabled:` فتُطابق زورًا.
        $tag = preg_replace('/class="[^"]*"/', '', $tag);

        $this->assertDoesNotMatchRegularExpression(
            '/\sdisabled(=|\s|$)/', $tag,
            'صندوق التحديد معطّل رغم أن الطرد بانتظار الاستلام.'
        );
    }
}
