<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
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
}
