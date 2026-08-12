<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Services\OrderPaymentService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\SalesPostingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPostingTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->accounting = app(AccountingService::class);
    }

    private function balance(string $code): float
    {
        return $this->accounting->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    public function test_confirm_posts_revenue_and_cogs_entries(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 60); // WAC = 60

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'عميل', 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->id, 'qty' => 2, 'unit_price' => 100]], 2026);

        app(OrderService::class)->confirm($order);
        $order->refresh();

        // قيدان أُنشئا.
        $this->assertNotNull($order->revenue_entry_id);
        $this->assertNotNull($order->cogs_entry_id);

        // طلب توصيل (channel=manual): مدين ذمم شركة التوصيل 1050 / دائن إيراد المبيعات.
        $this->assertEqualsWithDelta(200, $this->balance('1050'), 0.01); // COD receivable (asset debit)
        $this->assertEqualsWithDelta(0, $this->balance('1100'), 0.01);   // لا ذمم عملاء لطلبات التوصيل
        $this->assertEqualsWithDelta(200, $this->balance('4010'), 0.01); // revenue (credit)
        // التكلفة: مدين COGS 120 / دائن المخزون 120.
        $this->assertEqualsWithDelta(120, $this->balance('6000'), 0.01); // COGS (cost_of_goods debit)
    }

    public function test_posting_is_idempotent(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        app(InventoryService::class)->receive($product->defaultVariant, $warehouse, 5, 40);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'ع', 'customer_phone' => '0599000000',
        ], [['variant_id' => $product->defaultVariant->id, 'qty' => 1, 'unit_price' => 100]], 2026);

        app(OrderService::class)->confirm($order);
        $firstEntry = $order->fresh()->revenue_entry_id;

        // نداء الترحيل ثانيةً لا يُنشئ قيدًا جديدًا.
        app(SalesPostingService::class)->post($order->fresh());
        $this->assertSame($firstEntry, $order->fresh()->revenue_entry_id);
        $this->assertEqualsWithDelta(100, $this->balance('4010'), 0.01);
    }

    /**
     * الخصم يُطرح مرّة واحدة فقط: line_total مخصوم أصلًا، فلا يُطرح الخصم ثانيةً في القيد.
     * الذمم المدينة يجب أن تساوي إجمالي الطلب تمامًا (وإلا اختلّ رصيد العميل/المزوّد).
     */
    public function test_discounted_order_posts_receivable_equal_to_order_total(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 60);

        // 2 × 100 = 200، خصم 30 ⇒ الإجمالي 170.
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'عميل', 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->id, 'qty' => 2, 'unit_price' => 100, 'discount' => 30]], 2026);

        app(OrderService::class)->confirm($order);
        $order->refresh();

        $this->assertEqualsWithDelta(170, (float) $order->total, 0.01);
        // الذمم = الإجمالي 170 (لا 140 بطرح الخصم مرّتين).
        $this->assertEqualsWithDelta(170, $this->balance('1050'), 0.01);
        $this->assertEqualsWithDelta(170, $this->balance('4010'), 0.01);
    }

    /**
     * لقطة تكلفة الجملة تُحفظ عند إنشاء البنود — عليها تُبنى تقارير الربح (بلا لقطة تُحسب
     * التكلفة صفرًا فيتضخّم الربح) وقيد التكلفة وإرجاع المخزون.
     */
    public function test_order_items_capture_wholesale_cost_snapshot(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 60); // WAC = 60

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'عميل', 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 2, 'unit_price' => 100]], 2026);

        $this->assertEqualsWithDelta(60, (float) $order->items->first()->wholesale_cost_snapshot, 0.01);
    }

    /**
     * قيد التكلفة يُجمَّد على لقطة وقت البيع: تغيّر WAC لاحقًا لا يُعيد كتابة التكلفة
     * بأثر رجعي عند تعديل الطلب (وإلا اختلف دفتر المخزون عن حساب المخزون).
     */
    public function test_cogs_uses_frozen_snapshot_not_current_wac(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 60); // WAC = 60

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'عميل', 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 2, 'unit_price' => 100]], 2026);
        app(OrderService::class)->confirm($order);

        $this->assertEqualsWithDelta(120, $this->balance('6000'), 0.01); // 2 × 60

        // شراء لاحق يرفع WAC، ثم إعادة ترحيل الطلب: التكلفة تبقى على لقطة البيع.
        app(InventoryService::class)->receive($variant->fresh(), $warehouse, 10, 140);
        app(SalesPostingService::class)->repost($order->fresh());

        $this->assertEqualsWithDelta(120, $this->balance('6000'), 0.01); // لا تتغيّر
    }

    /**
     * دورة كاملة بخصم: الترحيل ثم التحصيل الكامل يُقفل المديونية إلى صفر تمامًا.
     * (قبل إصلاح ازدواج طرح الخصم كان الرصيد يصبح سالبًا بمقدار الخصم.)
     */
    public function test_full_collection_of_discounted_order_closes_receivable_to_zero(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 50);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'عميل', 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 2, 'unit_price' => 100, 'discount' => 40]], 2026);

        app(OrderService::class)->confirm($order);
        $order->refresh();
        $this->assertEqualsWithDelta(160, (float) $order->total, 0.01);

        $treasury = Treasury::where('is_active', true)->whereNotNull('gl_account_id')->firstOrFail();
        app(OrderPaymentService::class)->collect($order, $treasury->id, 160);

        $this->assertEqualsWithDelta(0, $this->balance('1050'), 0.01);  // المديونية أُقفلت تمامًا
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    /**
     * رسوم التوصيل خارج الدفاتر: الذمّة على شركة التوصيل = قيمة البضاعة بعد الخصم فقط،
     * ولا يظهر «إيراد الشحن» في القيد — المندوب يقبض الرسوم ويحتفظ بها أجرةً له.
     */
    public function test_shipping_is_excluded_from_revenue_entry(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 50);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'عميل', 'customer_phone' => '0599000000',
            'shipping_total' => 20,
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100, 'discount' => 10]], 2026);

        app(OrderService::class)->confirm($order);
        $order->refresh();

        // إجمالي الفاتورة على العميل 110 (100 − 10 + 20 شحن)…
        $this->assertEqualsWithDelta(110, (float) $order->total, 0.01);
        // …لكن الذمّة المُقيَّدة 90 فقط (بلا الشحن)، ولا سطر لإيراد الشحن.
        $this->assertEqualsWithDelta(90, $this->balance('1050'), 0.01);
        $this->assertEqualsWithDelta(0, $this->balance('4020'), 0.01);
    }
}
