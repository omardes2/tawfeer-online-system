<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * البيع بأقل من سعر الجملة — **لمدير النظام وحده**.
 *
 * ## القاعدة
 *
 * تعديل السعر يبقى مفتوحًا لمن كان يملكه: المسوّقون والمدراء وموظفو المبيعات
 * يُعدّلون الأسعار كما كانوا. والقيدُ على **الرقم** لا على **الوصول**: النزولُ
 * تحت سعر الجملة وحده مقصورٌ على مدير النظام.
 *
 * ## لماذا يُستثنى المدير
 *
 * البيع بأقل من الجملة قرارٌ تجاريّ يقع أحيانًا — تصفية راكد، أو تسويةُ شكوى،
 * أو خطأ سعرٍ يُصحَّح على طلبٍ قائم. ومنعُه عن الجميع كان يترك المدير بلا مخرجٍ
 * إلا تعطيلَ الحارس أو تحرير قاعدة البيانات.
 *
 * ## وأين يقع الحارس
 *
 * في `OrderService` لا في السياسة: قيدُ الرقم يجب أن يقع على **كل قنوات البيع**
 * — الشاشة ونقطة البيع والمتجر والطلب المُساعَد — لا على شاشة التعديل وحدها.
 */
class WholesaleFloorAdminOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sales;

    private User $affiliate;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->sales = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->sales->assignRole('sales');

        $this->affiliate = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->affiliate->assignRole('affiliate');

        $this->warehouse = Warehouse::firstOrFail();
        $this->product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 200, 'wholesale_price' => 120,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
        app(InventoryService::class)->openingStock($this->product->defaultVariant, $this->warehouse, 100, 100);
    }

    private function order(float $price = 200): Order
    {
        return app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599111222',
            'shipping_address' => 'الخليل', 'channel' => 'manual',
        ], [[
            'variant_id' => $this->product->defaultVariant->id, 'qty' => 1, 'unit_price' => $price,
        ]], (int) now()->year);
    }

    /** طلبٌ مُسنَدٌ لموظف المبيعات كي يراه ويُعدّله (لا يقرأ إلا طلباته). */
    private function assignedOrder(): Order
    {
        $this->actingAs($this->admin);
        $order = $this->order();
        $order->forceFill(['assigned_to' => $this->sales->id])->save();

        return $order->fresh();
    }

    // ────────── تعديل السعر يبقى مفتوحًا ──────────

    /** **موظف المبيعات يفتح صفحة التعديل** — تعديل السعر لم يُقيَّد. */
    public function test_a_sales_user_can_still_open_the_edit_page(): void
    {
        $order = $this->assignedOrder();

        $this->actingAs($this->sales)
            ->get(route('admin.sales.orders.edit', $order))
            ->assertOk();
    }

    /** ويُعدّل السعر فعلًا ما دام فوق الجملة. */
    public function test_a_sales_user_can_change_a_price_above_wholesale(): void
    {
        $order = $this->assignedOrder();

        $this->actingAs($this->sales)
            ->put(route('admin.sales.orders.update', $order), [
                'customer_name' => 'زبون', 'customer_phone' => '0599111222',
                'shipping_address' => 'الخليل',
                'items' => [[
                    'variant' => $this->product->defaultVariant->uuid,
                    'qty' => 1, 'unit_price' => 150, 'discount' => 0,
                ]],
            ]);

        $this->assertEqualsWithDelta(150.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    // ────────── حدّ سعر الجملة ──────────

    /** **مدير النظام يبيع بأقل من الجملة.** */
    public function test_the_admin_may_price_below_wholesale(): void
    {
        $this->actingAs($this->admin);

        $order = $this->order(price: 70); // الجملة ١٢٠.

        $this->assertEqualsWithDelta(70.0, (float) $order->items->first()->unit_price, 0.01);
    }

    /** **وموظف المبيعات يُمنع.** */
    public function test_a_sales_user_may_not_price_below_wholesale(): void
    {
        $this->actingAs($this->sales);

        $this->expectException(ValidationException::class);
        $this->order(price: 70);
    }

    /** **والمسوّق يُمنع كذلك.** */
    public function test_an_affiliate_may_not_price_below_wholesale(): void
    {
        $this->actingAs($this->affiliate);

        $this->expectException(ValidationException::class);
        $this->order(price: 70);
    }

    /** والبيع بالجملة فأعلى مسموحٌ للجميع. */
    public function test_anyone_may_price_at_or_above_wholesale(): void
    {
        $this->actingAs($this->sales);

        $order = $this->order(price: 120);

        $this->assertEqualsWithDelta(120.0, (float) $order->items->first()->unit_price, 0.01);
    }

    /**
     * **وزائرُ المتجر تحت الحارس.**
     *
     * الاستثناء بالدور، وبلا مستخدمٍ لا دورَ يُفحص — فلا يُفتح الباب لطلبٍ
     * يأتي من الويب بلا حساب.
     */
    public function test_a_guest_order_is_still_guarded(): void
    {
        $this->expectException(ValidationException::class);
        $this->order(price: 70);
    }

    /** والحدّ يقع على التعديل كما على الإنشاء — لا على قناةٍ دون أخرى. */
    public function test_the_floor_applies_when_editing_too(): void
    {
        $order = $this->assignedOrder();

        $this->actingAs($this->sales)
            ->put(route('admin.sales.orders.update', $order), [
                'customer_name' => 'زبون', 'customer_phone' => '0599111222',
                'shipping_address' => 'الخليل',
                'items' => [[
                    'variant' => $this->product->defaultVariant->uuid,
                    'qty' => 1, 'unit_price' => 70, 'discount' => 0,
                ]],
            ]);

        // السعر لم يتغيّر: الحارس ردّ التعديل.
        $this->assertEqualsWithDelta(200.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }

    /** ومدير النظام يُعدّل الطلب القائم إلى ما دون الجملة. */
    public function test_the_admin_may_edit_a_price_below_wholesale(): void
    {
        $order = $this->assignedOrder();

        $this->actingAs($this->admin)
            ->put(route('admin.sales.orders.update', $order), [
                'customer_name' => 'زبون', 'customer_phone' => '0599111222',
                'shipping_address' => 'الخليل',
                'items' => [[
                    'variant' => $this->product->defaultVariant->uuid,
                    'qty' => 1, 'unit_price' => 70, 'discount' => 0,
                ]],
            ]);

        $this->assertEqualsWithDelta(70.0, (float) $order->fresh()->items->first()->unit_price, 0.01);
    }
}
