<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionRule;
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
 * تفريغ بيانات التجربة: يمسح الحركات (والأصناف/الأطراف حسب النطاق) ويُبقي الإعدادات
 * والبنية — المستخدمين ودليل الحسابات والخزائن والمستودعات وقواعد العمولات.
 */
class ResetTestDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    /** طلب مُرحّل محاسبيًا + مخزون + قاعدة عمولة. */
    private function seedActivity(): Order
    {
        CommissionRule::create(['earner_type' => 'sales', 'method' => 'percent', 'rate' => 0.05, 'priority' => 1]);

        $product = Product::factory()->active()->create(['retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 10, 60);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 1, 'unit_price' => 100, 'discount' => 0]], 2026);

        app(OrderService::class)->confirm($order->fresh('items'));

        return $order->fresh();
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->seedActivity();

        $this->artisan('system:reset-test-data')->assertSuccessful();

        $this->assertGreaterThan(0, Order::count());
        $this->assertGreaterThan(0, JournalEntry::count());
    }

    public function test_force_clears_transactions_and_keeps_configuration(): void
    {
        $this->seedActivity();
        $usersBefore = User::count();

        $this->artisan('system:reset-test-data', ['--force' => true])
            ->expectsConfirmation('تأكيد الحذف النهائي لهذه البيانات؟ لا يمكن التراجع.', 'yes')
            ->assertSuccessful();

        // حركات ممسوحة.
        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, JournalEntry::count());
        $this->assertEquals(0, InventoryStock::count());

        // إعدادات وبنية باقية.
        $this->assertEquals($usersBefore, User::count());
        $this->assertGreaterThan(0, Account::count());
        $this->assertGreaterThan(0, Treasury::count());
        $this->assertGreaterThan(0, Warehouse::count());
        $this->assertEquals(1, CommissionRule::count());   // القواعد إعداد لا حركة
        $this->assertGreaterThan(0, Product::count());     // النطاق الافتراضي لا يمسّ الأصناف
    }

    public function test_catalog_scope_also_clears_products(): void
    {
        $this->seedActivity();

        $this->artisan('system:reset-test-data', ['--scope' => 'catalog', '--force' => true])
            ->expectsConfirmation('تأكيد الحذف النهائي لهذه البيانات؟ لا يمكن التراجع.', 'yes')
            ->assertSuccessful();

        $this->assertEquals(0, Product::count());
        $this->assertGreaterThan(0, Account::count()); // الدليل باقٍ
    }

    public function test_declining_confirmation_keeps_data(): void
    {
        $this->seedActivity();

        $this->artisan('system:reset-test-data', ['--force' => true])
            ->expectsConfirmation('تأكيد الحذف النهائي لهذه البيانات؟ لا يمكن التراجع.', 'no')
            ->assertSuccessful();

        $this->assertGreaterThan(0, Order::count());
    }

    public function test_unknown_scope_is_rejected(): void
    {
        $this->artisan('system:reset-test-data', ['--scope' => 'everything'])->assertFailed();
    }
}
