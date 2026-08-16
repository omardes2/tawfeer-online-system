<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * لقطة قناة الإعلان على الطلب.
 *
 * القناة معروفةٌ من منشئ الطلب، فكان يمكن استنتاجها وقت العرض. لكنّ الاستنتاج
 * يقرأ الحاضر: نقلُ موظفةٍ إلى صفحة أخرى ينقل معها كل طلباتها السابقة، فيتغيّر
 * تقرير الشهر الماضي بصمت ويُنسَب صرفُ صفحةٍ إلى مبيعات أخرى.
 */
class OrderAdChannelSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function business(string $name): DeliveryBusiness
    {
        return DeliveryBusiness::create([
            'provider' => 'opost',
            'external_id' => 'biz-'.Str::slug($name),
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function employeeOn(DeliveryBusiness $business, string $name): User
    {
        return User::factory()->create([
            'branch_id' => Branch::default()->id,
            'name' => $name,
            'delivery_business_id' => $business->id,
        ]);
    }

    private function placeOrder(User $creator): Order
    {
        $variant = Product::factory()->create()->defaultVariant;
        $variant->update(['average_cost' => 40]);

        // `created_by` يُقرأ من المستخدم الحالي، ومنه تُشتقّ القناة.
        $this->actingAs($creator);

        return app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100]], 2026);
    }

    public function test_the_order_takes_the_channel_of_its_creators_business(): void
    {
        $business = $this->business('توفير اون لاين');
        $channel = AdChannel::where('name', 'توفير اون لاين')->firstOrFail();
        $channel->update(['delivery_business_id' => $business->id]);

        $order = $this->placeOrder($this->employeeOn($business, 'فله شاهين'));

        $this->assertSame($channel->id, $order->ad_channel_id);
    }

    /** نقلُ الموظفة لاحقًا لا يُعيد كتابة إسناد طلباتها السابقة. */
    public function test_moving_the_employee_does_not_rewrite_past_orders(): void
    {
        $first = $this->business('شاهين اليت هوم');
        $second = $this->business('جاردن هوم');

        $channelA = AdChannel::where('name', 'شاهين اليت هوم')->firstOrFail();
        $channelA->update(['delivery_business_id' => $first->id]);
        $channelB = AdChannel::where('name', 'جاردن هوم')->firstOrFail();
        $channelB->update(['delivery_business_id' => $second->id]);

        $employee = $this->employeeOn($first, 'هالة الأيوبي');
        $old = $this->placeOrder($employee);
        $this->assertSame($channelA->id, $old->ad_channel_id);

        $employee->update(['delivery_business_id' => $second->id]);
        $new = $this->placeOrder($employee);

        $this->assertSame($channelA->id, $old->fresh()->ad_channel_id, 'تغيّر إسناد طلبٍ ماضٍ بنقل الموظفة.');
        $this->assertSame($channelB->id, $new->ad_channel_id);
    }

    /** موظفة على حسابٍ بلا قناة مربوطة: الطلب بلا قناة، لا بقناة خاطئة. */
    public function test_an_unlinked_business_leaves_the_order_without_a_channel(): void
    {
        $order = $this->placeOrder($this->employeeOn($this->business('حساب بلا صفحة'), 'موظفة'));

        $this->assertNull($order->ad_channel_id);
    }

    /** طلب المتجر الإلكتروني لا منشئ له — ولا إعلان صفحةٍ وراءه. */
    public function test_an_order_without_a_creator_has_no_channel(): void
    {
        $variant = Product::factory()->create()->defaultVariant;

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'customer_name' => 'زبون الموقع',
            'customer_phone' => '0599111111',
            'channel' => 'web',
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100]], 2026);

        $this->assertNull($order->ad_channel_id);
    }
}
