<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\PriceListItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * تصحيح صنفٍ أُدخل خطأً في فواتير منفَّذة.
 *
 * الخطأ يقع في أربعة دفاتر لا في اسمٍ يُعرض، فالاختبار يتبع الأربعة: البند،
 * والمخزون (خُصم من صنفٍ لم يخرج)، وأساس العمولة، والمال الذي **يجب ألّا
 * يتغيّر**.
 */
class SwapOrderProductTest extends TestCase
{
    use RefreshDatabase;

    private User $earner;

    private ProductVariant $wrong;

    private ProductVariant $right;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();

        $list = PriceList::create(['name' => 'قائمة أسعار سائد', 'is_active' => true]);

        $this->earner = User::factory()->create([
            'name' => 'سائد شاهين',
            'branch_id' => Branch::default()->id,
            'price_list_id' => $list->id,
        ]);
        $this->earner->assignRole('affiliate');

        // الخطأ: بيع 100 · جملته 100 ⇒ هامش صفر (وهو ما دفع إلى المراجعة أصلًا).
        $this->wrong = $this->variantFor('عطر 250 ملم', wholesale: 100, cost: 60);
        // الصحيح: جملته 70 ⇒ الهامش الحقيقي 30.
        $this->right = $this->variantFor('عطر سمارت', wholesale: 70, cost: 45);

        PriceListItem::create([
            'price_list_id' => $list->id,
            'variant_id' => $this->right->id,
            'price' => 65,   // قائمته أرخص من الجملة العامّة ⇒ الهامش 35.
        ]);

        $this->stock($this->wrong, 10);
        $this->stock($this->right, 10);
    }

    private function variantFor(string $name, float $wholesale, float $cost): ProductVariant
    {
        $product = Product::factory()->create([
            'name' => $name, 'retail_price' => 100, 'wholesale_price' => $wholesale,
        ]);

        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100, 'wholesale_price' => $wholesale, 'average_cost' => $cost]);

        return $variant->fresh();
    }

    private function stock(ProductVariant $variant, float $qty): void
    {
        InventoryStock::updateOrCreate(
            ['variant_id' => $variant->id, 'warehouse_id' => $this->warehouse->id],
            ['on_hand' => $qty, 'reserved' => 0],
        );
    }

    private function onHand(ProductVariant $variant): float
    {
        return (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand');
    }

    /** فاتورةٌ منفَّذة بالصنف الخطأ، مع عمولةٍ محسوبةٍ على أساسه. */
    private function invoice(float $qty = 2): OrderItem
    {
        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'affiliate_id' => $this->earner->id,
            'status' => 'delivered',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $this->wrong->id,
            'qty' => $qty, 'unit_price' => 100, 'discount' => 0, 'line_total' => 100 * $qty,
            'qty_reserved' => $qty, 'qty_shipped' => $qty,
            'wholesale_cost_snapshot' => 60, 'wholesale_price_snapshot' => 100,
        ]);

        CommissionEntry::create([
            'earner_type' => 'affiliate', 'earner_id' => $this->earner->id,
            'order_id' => $order->id, 'order_item_id' => $item->id, 'variant_id' => $this->wrong->id,
            'entry_type' => 'accrual', 'basis' => 0, 'rate' => 1.0, 'amount' => 0,
            'wholesale_cost_snapshot' => 100,
            'rule_snapshot' => ['method' => 'margin', 'rate' => 1.0, 'default' => true],
            'state' => 'pending',
        ]);

        return $item;
    }

    private function runSwap(array $options = []): PendingCommand
    {
        return $this->artisan('sales:swap-order-product', array_merge([
            'from' => 'عطر 250 ملم', 'to' => 'عطر سمارت',
        ], $options));
    }

    // ────────── العرض لا يكتب ──────────

    /** بلا `--apply` لا يتغيّر شيء — ولا حتى المخزون. */
    public function test_a_dry_run_changes_nothing(): void
    {
        $item = $this->invoice();

        $this->runSwap()->assertSuccessful();

        $this->assertSame($this->wrong->id, $item->fresh()->variant_id);
        $this->assertSame(10.0, $this->onHand($this->wrong));
        $this->assertSame(10.0, $this->onHand($this->right));
    }

    // ────────── التنفيذ ──────────

    /** البند ينتقل إلى الصنف الصحيح. */
    public function test_it_moves_the_item_to_the_right_product(): void
    {
        $item = $this->invoice();

        $this->runSwap(['--apply' => true])->assertSuccessful();

        $this->assertSame($this->right->id, $item->fresh()->variant_id);
    }

    /**
     * **ويبقى معرّف البند كما هو.**
     *
     * مسار تعديل الطلب القائم يحذف البنود ويُنشئها، و`order_item_id` على حركة
     * العمولة `nullOnDelete` — فيفقد الكشف ربطه بالبند إلى الأبد. هذا الاختبار
     * هو ما يمنع الانزلاق إلى ذلك المسار لاحقًا.
     */
    public function test_the_item_keeps_its_id_so_commissions_stay_linked(): void
    {
        $item = $this->invoice();
        $id = $item->id;

        $this->runSwap(['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('order_items', ['id' => $id, 'variant_id' => $this->right->id]);
        $this->assertSame($id, CommissionEntry::where('order_id', $item->order_id)->value('order_item_id'));
    }

    /** والمخزون يعود إلى الصنف الخطأ ويُخصم من الصحيح. */
    public function test_the_stock_moves_between_the_two_products(): void
    {
        $this->invoice(qty: 2);

        $this->runSwap(['--apply' => true])->assertSuccessful();

        $this->assertSame(12.0, $this->onHand($this->wrong));   // عاد ما لم يخرج
        $this->assertSame(8.0, $this->onHand($this->right));    // وخرج ما بيع فعلًا
    }

    /**
     * **والمال لا يتغيّر.**
     *
     * الزبون دفع مبلغًا وسنده يحمله؛ تغييرُه يجعل الدفتر يخالف المقبوض. ما
     * يتغيّر هوية البضاعة وتكلفتها لا ثمنُها.
     */
    public function test_the_money_is_untouched(): void
    {
        $item = $this->invoice();
        $before = $item->order->total;

        $this->runSwap(['--apply' => true])->assertSuccessful();

        $fresh = $item->fresh();

        $this->assertEquals(100, $fresh->unit_price);
        $this->assertEquals(200, $fresh->line_total);
        $this->assertEquals($before, $fresh->order->fresh()->total);
    }

    /** ولقطة سعر الجملة تصير سعر **قائمة المسوّق** للصنف الصحيح. */
    public function test_the_wholesale_snapshot_becomes_his_list_price(): void
    {
        $item = $this->invoice();

        $this->runSwap(['--apply' => true])->assertSuccessful();

        $this->assertEquals(65, $item->fresh()->wholesale_price_snapshot);
        $this->assertEquals(45, $item->fresh()->wholesale_cost_snapshot);
    }

    /** والعمولة تُعاد على الأساس الجديد: (100 − 65) × 2 = 70 بدل صفر. */
    public function test_the_commission_is_recomputed(): void
    {
        $item = $this->invoice(qty: 2);

        $this->runSwap(['--apply' => true])->assertSuccessful();

        $entry = CommissionEntry::where('order_id', $item->order_id)->firstOrFail();

        $this->assertEquals(70, $entry->amount);
    }

    // ────────── النطاق ──────────

    /** وفواتير مسوّقٍ آخر لا تُمسّ حين يُحدَّد المسوّق. */
    public function test_it_respects_the_earner_filter(): void
    {
        $other = User::factory()->create(['branch_id' => Branch::default()->id]);
        $other->assignRole('affiliate');

        $mine = $this->invoice();

        $theirs = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'affiliate_id' => $other->id,
            'status' => 'delivered',
        ]);
        $theirItem = OrderItem::create([
            'order_id' => $theirs->id, 'variant_id' => $this->wrong->id,
            'qty' => 1, 'unit_price' => 100, 'discount' => 0, 'line_total' => 100,
            'qty_shipped' => 1, 'wholesale_cost_snapshot' => 60, 'wholesale_price_snapshot' => 100,
        ]);

        $this->runSwap(['--apply' => true, '--earner' => $this->earner->id])->assertSuccessful();

        $this->assertSame($this->right->id, $mine->fresh()->variant_id);
        $this->assertSame($this->wrong->id, $theirItem->fresh()->variant_id);
    }

    /** والطلبات الملغاة تُترك — لا أثر لها يُصحَّح. */
    public function test_cancelled_orders_are_skipped(): void
    {
        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'cancelled',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $this->wrong->id,
            'qty' => 1, 'unit_price' => 100, 'discount' => 0, 'line_total' => 100,
            'wholesale_cost_snapshot' => 60, 'wholesale_price_snapshot' => 100,
        ]);

        $this->runSwap(['--apply' => true])->assertSuccessful();

        $this->assertSame($this->wrong->id, $item->fresh()->variant_id);
    }

    /** ولا يُصحَّح صنفٌ إلى نفسه. */
    public function test_it_refuses_the_same_product(): void
    {
        $this->artisan('sales:swap-order-product', ['from' => 'عطر سمارت', 'to' => 'عطر سمارت'])
            ->assertFailed();
    }

    /** ويرفض اسمًا يطابق أكثر من صنف بدل أن يخمّن. */
    public function test_it_refuses_an_ambiguous_name(): void
    {
        Product::factory()->create(['name' => 'عطر سمارت الجديد']);

        $this->artisan('sales:swap-order-product', ['from' => 'عطر 250 ملم', 'to' => 'عطر سمارت'])
            ->assertFailed();
    }
}
