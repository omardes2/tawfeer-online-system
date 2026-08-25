<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعديل فاتورة مبيعاتٍ مباشرة بعد تسليمها.
 *
 * المبيعة المباشرة تُنشأ وتُسلَّم في خطوةٍ واحدة، فتصير `delivered` فورًا وتخرج
 * من بوّابة التعديل العامّة. لكن **لا شركة توصيل فيها ولا طرد**، فلا شيء
 * يفترق عن الخارج إن عُدّلت — بخلاف الطلب المُرسَل الذي تحمل الشركة نسخته.
 *
 * والفحص الحاسم ليس «هل حُفظ السعر؟» بل **«هل تحرّك الثلاثة معًا؟»**: المخزون،
 * والقيد المحاسبي، وعمولة البائع.
 */
class DirectSaleEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();

        $this->seller = User::factory()->create(['name' => 'هالة', 'branch_id' => Branch::default()->id]);
        $this->seller->assignRole('sales');

        $this->product = Product::factory()->create([
            'name' => 'مكنسة', 'retail_price' => 100, 'wholesale_price' => 60,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);

        app(InventoryService::class)->openingStock(
            $this->product->defaultVariant, $this->warehouse, 100, 40,
        );
    }

    /** مبيعةٌ مباشرة مُسلَّمة، بكمية وسعرٍ محدّدين. */
    private function directSale(float $qty = 2, float $price = 100): Order
    {
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
            'channel' => 'pos',
            'assigned_to' => $this->seller->id,
        ], [[
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => $qty, 'unit_price' => $price,
        ]], (int) now()->year);

        app(OrderService::class)->fulfillDirect($order);

        return $order->refresh();
    }

    private function edit(Order $order, float $qty, float $price, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put(
            route('admin.sales.orders.update', $order),
            [
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'items' => [[
                    'variant' => $this->product->defaultVariant->uuid,
                    'qty' => $qty, 'unit_price' => $price,
                ]],
            ],
        );
    }

    private function onHand(): float
    {
        return (float) InventoryStock::where('variant_id', $this->product->defaultVariant->id)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand');
    }

    // ────────── البوّابة ──────────

    /** شاشة التعديل تفتح لمدير النظام على مبيعةٍ مباشرة مُسلَّمة. */
    public function test_the_admin_can_open_a_delivered_direct_sale(): void
    {
        $order = $this->directSale();

        $this->assertSame('delivered', $order->status);

        $this->actingAs($this->admin)->get(route('admin.sales.orders.edit', $order))->assertOk();
    }

    /** ولا تفتح لمن لا يرى طلبات الجميع. */
    public function test_a_seller_cannot_open_it(): void
    {
        $order = $this->directSale();

        $this->actingAs($this->seller)->get(route('admin.sales.orders.edit', $order))->assertForbidden();
    }

    /** ولا تفتح على طلبٍ عاديٍّ سُلّم — الطرد عند الشركة. */
    public function test_a_delivered_normal_order_stays_closed(): void
    {
        $order = $this->directSale();
        $order->update(['channel' => 'web']);

        $this->actingAs($this->admin)->get(route('admin.sales.orders.edit', $order->refresh()))->assertForbidden();
    }

    // ────────── الأثر الثلاثيّ ──────────

    /** تغيير الكمية يُصحّح المخزون. */
    public function test_changing_the_quantity_moves_stock(): void
    {
        $order = $this->directSale(qty: 2);

        $this->assertEqualsWithDelta(98.0, $this->onHand(), 0.001);

        $this->edit($order, qty: 5, price: 100)->assertRedirect();

        // 100 − 5 = 95: أُعيدت الاثنتان ثم صُرفت الخمس.
        $this->assertEqualsWithDelta(95.0, $this->onHand(), 0.001);
    }

    /** وتغيير السعر يُصحّح قيد الإيراد في مكانه — لا قيدًا ثانيًا. */
    public function test_changing_the_price_updates_the_same_journal_entry(): void
    {
        $order = $this->directSale(qty: 1, price: 100);

        $entryId = $order->revenue_entry_id;
        $this->assertNotNull($entryId);

        $this->edit($order, qty: 1, price: 250)->assertRedirect();

        $this->assertSame($entryId, $order->refresh()->revenue_entry_id);

        $revenue = (float) JournalLine::where('journal_entry_id', $entryId)->sum('credit');
        $this->assertEqualsWithDelta(250.0, $revenue, 0.01);
    }

    /** والإجمالي يتبع البنود. */
    public function test_the_total_follows_the_items(): void
    {
        $order = $this->directSale(qty: 2, price: 100);

        $this->edit($order, qty: 3, price: 150)->assertRedirect();

        $this->assertEqualsWithDelta(450.0, (float) $order->refresh()->total, 0.01);
    }

    /**
     * **وعمولة البائع تُعاد على البنود الجديدة.**
     *
     * تعديل الفاتورة يحذف بنودها ويُنشئها، و`order_item_id` على العمولة
     * `nullOnDelete` — فبلا عكسٍ وإعادة استحقاق تبقى الحركة القديمة معلّقةً
     * بمبلغ فاتورةٍ لم تعد موجودة.
     */
    public function test_the_commission_is_reversed_and_re_accrued(): void
    {
        $order = $this->directSale(qty: 1, price: 100);

        $before = CommissionEntry::where('order_id', $order->id)
            ->where('entry_type', 'accrual')->whereNotIn('state', ['reversed', 'cancelled'])->sum('basis');

        $this->assertEqualsWithDelta(100.0, (float) $before, 0.01);

        $this->edit($order, qty: 1, price: 300)->assertRedirect();

        $live = CommissionEntry::where('order_id', $order->id)
            ->where('entry_type', 'accrual')->whereNotIn('state', ['reversed', 'cancelled'])->get();

        $this->assertCount(1, $live);
        $this->assertEqualsWithDelta(300.0, (float) $live->sum('basis'), 0.01);
    }

    /** والقديمة تُعلَّم معكوسةً ولا تُحذف — الدفتر لا يُمحى. */
    public function test_the_old_entry_is_marked_reversed_not_deleted(): void
    {
        $order = $this->directSale(qty: 1, price: 100);

        $this->edit($order, qty: 1, price: 300)->assertRedirect();

        $this->assertTrue(
            CommissionEntry::where('order_id', $order->id)->where('state', 'reversed')->exists(),
        );
    }

    /** ولا تتضاعف الحركات بتعديلين متتاليين. */
    public function test_editing_twice_leaves_one_live_entry(): void
    {
        $order = $this->directSale(qty: 1, price: 100);

        $this->edit($order, qty: 1, price: 200)->assertRedirect();
        $this->edit($order->refresh(), qty: 1, price: 400)->assertRedirect();

        $live = CommissionEntry::where('order_id', $order->id)
            ->where('entry_type', 'accrual')->whereNotIn('state', ['reversed', 'cancelled'])->get();

        $this->assertCount(1, $live);
        $this->assertEqualsWithDelta(400.0, (float) $live->sum('basis'), 0.01);
    }

    /** ومخزونٌ لا يكفي للكمية الجديدة يُوقف التعديل بلا أثرٍ جزئيّ. */
    public function test_insufficient_stock_aborts_the_whole_edit(): void
    {
        $order = $this->directSale(qty: 1, price: 100);

        $this->edit($order, qty: 9999, price: 100);

        // لا شيء تغيّر: البند والمخزون كما كانا.
        $this->assertEqualsWithDelta(1.0, (float) $order->refresh()->items->first()->qty, 0.001);
        $this->assertEqualsWithDelta(99.0, $this->onHand(), 0.001);
    }
}
