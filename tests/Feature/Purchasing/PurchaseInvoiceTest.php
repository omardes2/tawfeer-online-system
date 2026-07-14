<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    private AccountingService $accounting;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(PurchaseInvoiceService::class);
        $this->accounting = app(AccountingService::class);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();
    }

    private function balance(string $code): float
    {
        return $this->accounting->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    private function makeInvoice(float $unitCost = 50, float $qty = 10, float $taxRate = 15): PurchaseInvoice
    {
        return $this->service->create(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_cost' => $unitCost, 'tax_rate' => $taxRate]],
        );
    }

    public function test_create_computes_totals(): void
    {
        $inv = $this->makeInvoice(50, 10, 15);
        $this->assertEquals(500, (float) $inv->subtotal);
        $this->assertEquals(75, (float) $inv->tax_amount);
        $this->assertEquals(575, (float) $inv->total);
        $this->assertEquals('draft', $inv->status);
    }

    public function test_post_records_inventory_and_payable_balanced(): void
    {
        $inv = $this->makeInvoice(50, 10, 15);
        $this->service->approve($inv);
        $this->service->post($inv);

        $inv->refresh();
        $this->assertEquals('posted', $inv->status);
        $this->assertNotNull($inv->journal_entry_id);
        $this->assertEqualsWithDelta(500, $this->balance('1200'), 0.01); // inventory
        $this->assertEqualsWithDelta(75, $this->balance('1250'), 0.01);  // recoverable input tax (asset)
        $this->assertEqualsWithDelta(575, $this->balance('2010'), 0.01); // accounts payable
    }

    public function test_post_increases_warehouse_stock(): void
    {
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $before = (float) (InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand') ?? 0);

        $inv = $this->makeInvoice(50, 10, 15);
        $this->service->approve($inv);
        $this->service->post($inv);

        $after = (float) InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');
        $this->assertEqualsWithDelta($before + 10, $after, 0.001);
    }

    public function test_store_defines_new_product_from_invoice_line(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();
        $this->actingAs($admin);

        $this->post(route('admin.purchasing.invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['new_name' => 'صنف جديد للاختبار', 'sell_price' => 120, 'qty' => 5, 'unit_cost' => 80, 'tax_rate' => 0]],
        ])->assertRedirect();

        $product = Product::where('name', 'صنف جديد للاختبار')->first();
        $this->assertNotNull($product);
        $this->assertEqualsWithDelta(120, (float) $product->retail_price, 0.01);
        $variant = $product->defaultVariant()->firstOrFail();

        $inv = PurchaseInvoice::latest('id')->first();
        $this->assertEquals($variant->id, $inv->items->first()->variant_id);

        $this->service->approve($inv);
        $this->service->post($inv->fresh());

        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $onHand = (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');
        $this->assertEqualsWithDelta(5, $onHand, 0.001);
    }

    public function test_post_is_idempotent(): void
    {
        $inv = $this->makeInvoice();
        $this->service->approve($inv);
        $this->service->post($inv);
        $je = $inv->fresh()->journal_entry_id;
        $this->service->post($inv->fresh()); // second call — no double posting
        $this->assertEquals($je, $inv->fresh()->journal_entry_id);
        $this->assertEqualsWithDelta(575, $this->balance('2010'), 0.01);
    }

    public function test_cannot_post_unapproved(): void
    {
        $inv = $this->makeInvoice();
        $this->expectException(ValidationException::class);
        $this->service->post($inv);
    }

    public function test_payment_reduces_payable_and_sets_status(): void
    {
        $inv = $this->makeInvoice(50, 10, 15); // total 575
        $this->service->approve($inv);
        $this->service->post($inv);
        $cb = Treasury::where('code', 'CB-MAIN')->first();

        $this->service->pay($inv->fresh(), $cb->id, 275);
        $inv->refresh();
        $this->assertEquals('partial', $inv->payment_status);
        $this->assertEqualsWithDelta(300, $inv->balanceDue(), 0.01);
        $this->assertEqualsWithDelta(300, $this->balance('2010'), 0.01); // payable reduced

        $this->service->pay($inv->fresh(), $cb->id, 300);
        $inv->refresh();
        $this->assertEquals('paid', $inv->payment_status);
        $this->assertEqualsWithDelta(0, $inv->balanceDue(), 0.01);
        $this->assertEqualsWithDelta(0, $this->balance('2010'), 0.01);
    }

    public function test_cannot_pay_more_than_due(): void
    {
        $inv = $this->makeInvoice();
        $this->service->approve($inv);
        $this->service->post($inv);
        $cb = Treasury::where('code', 'CB-MAIN')->first();

        $this->expectException(ValidationException::class);
        $this->service->pay($inv->fresh(), $cb->id, 99999);
    }

    public function test_reverse_creates_reversing_entry_and_blocks_when_paid(): void
    {
        $inv = $this->makeInvoice();
        $this->service->approve($inv);
        $this->service->post($inv);

        $this->service->reverse($inv->fresh());
        $inv->refresh();
        $this->assertEquals('reversed', $inv->status);
        $this->assertNotNull($inv->reversal_entry_id);
        $this->assertEqualsWithDelta(0, $this->balance('2010'), 0.01); // payable cleared by reversal
    }

    public function test_web_workflow_and_permission_boundary(): void
    {
        // sales role lacks purchasing.invoices.* → forbidden
        $sales = User::factory()->create(['branch_id' => Branch::default()->id]);
        $sales->assignRole('sales');
        $this->actingAs($sales)->get(route('admin.purchasing.invoices.index'))->assertForbidden();

        // admin can create via HTTP then post
        $admin = User::where('email', '!=', $sales->email)->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();
        $this->actingAs($admin);
        $this->post(route('admin.purchasing.invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertRedirect();

        $inv = PurchaseInvoice::latest('id')->first();
        $this->assertEquals(200, (float) $inv->total);
        $this->post(route('admin.purchasing.invoices.approve', $inv))->assertRedirect();
        $this->post(route('admin.purchasing.invoices.post', $inv))->assertRedirect();
        $this->assertEquals('posted', $inv->fresh()->status);
    }
}
