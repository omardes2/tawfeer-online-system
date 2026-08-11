<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Category;
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
use Illuminate\Support\Facades\Artisan;
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

    public function test_new_product_defined_and_stocked_immediately_on_save(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();
        $this->actingAs($admin);

        $this->post(route('admin.purchasing.invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['new_name' => 'صنف جديد للاختبار', 'sell_price' => 120, 'qty' => 5, 'unit_cost' => 80, 'tax_rate' => 0]],
        ])->assertRedirect();

        // الحفظ = ترحيل فوري: يُنشأ المنتج ويدخل المخزون في الحال (لا مسودّة).
        $inv = PurchaseInvoice::latest('id')->first();
        $this->assertSame('posted', $inv->status);
        $this->assertNotNull($inv->journal_entry_id);
        $this->assertNotNull($inv->items->first()->variant_id);

        $product = Product::where('name', 'صنف جديد للاختبار')->first();
        $this->assertNotNull($product);
        $this->assertEqualsWithDelta(120, (float) $product->retail_price, 0.01);
        $variant = $product->defaultVariant()->firstOrFail();

        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $onHand = (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');
        $this->assertEqualsWithDelta(5, $onHand, 0.001);
    }

    /** بعد تفريغ البيانات (لا فئات) — تعريف صنف جديد من الفاتورة عند الترحيل ينجح (فئة افتراضية). */
    public function test_new_product_from_invoice_works_when_no_categories_exist(): void
    {
        Artisan::call('demo:clear', ['--force' => true]);
        $this->assertSame(0, Category::count());

        $supplier = Supplier::factory()->create();
        $admin = User::where('email', 'admin@tawfeer.online')->first();

        $this->actingAs($admin)->post(route('admin.purchasing.invoices.store'), [
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['new_name' => 'خشب', 'qty' => 10, 'unit_cost' => 20, 'tax_rate' => 0]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // يُنشأ فورًا مع الحفظ (فئة افتراضية تُستحدث عند غيابها).
        $this->assertDatabaseHas('products', ['name' => 'خشب']);
        $this->assertSame('posted', PurchaseInvoice::latest('id')->first()->status);
        $this->assertGreaterThan(0, Category::count());
    }

    public function test_create_and_edit_pages_render_ok(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();
        $this->actingAs($admin)->get(route('admin.purchasing.invoices.create'))->assertOk();

        $inv = $this->makeInvoice(50, 2, 0);
        $this->actingAs($admin)->get(route('admin.purchasing.invoices.edit', $inv))->assertOk();
    }

    public function test_update_draft_invoice_replaces_items_and_recomputes_totals(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();
        $this->actingAs($admin);

        $inv = $this->makeInvoice(50, 10, 0); // إجمالي 500
        $this->assertEqualsWithDelta(500, (float) $inv->total, 0.01);

        $this->put(route('admin.purchasing.invoices.update', $inv), [
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 3, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $inv->refresh();
        $this->assertEqualsWithDelta(300, (float) $inv->total, 0.01);
        $this->assertCount(1, $inv->items);
        $this->assertEqualsWithDelta(3, (float) $inv->items->first()->qty, 0.001);
    }

    /** الفاتورة المُرحّلة صارت قابلة للتعديل (يُصحَّح قيدها ومخزونها) — قرار إداري. */
    public function test_posted_invoice_can_be_updated_via_web(): void
    {
        $inv = $this->makeInvoice(50, 10, 0);
        $this->service->approve($inv);
        $this->service->post($inv);

        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();
        $this->actingAs($admin)->put(route('admin.purchasing.invoices.update', $inv->fresh()), [
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 3, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(300, (float) $inv->fresh()->total, 0.01);
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
    // ---- الحفظ الفوري + تعديل/حذف المُرحّلة (قرار إداري: خطوة واحدة) ----

    private function stock(): float
    {
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();

        return (float) (InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand') ?? 0);
    }

    /** الحفظ خطوة واحدة: تُنشأ الفاتورة وتُرحّل محاسبيًا ويدخل المخزون فورًا. */
    public function test_create_and_post_in_one_step(): void
    {
        $before = $this->stock();

        $inv = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 50, 'tax_rate' => 15]],
        );

        $this->assertEquals('posted', $inv->status);
        $this->assertNotNull($inv->journal_entry_id);
        $this->assertEqualsWithDelta(500, $this->balance('1200'), 0.01);
        $this->assertEqualsWithDelta(575, $this->balance('2010'), 0.01);
        $this->assertEqualsWithDelta($before + 10, $this->stock(), 0.001);
    }

    /** تعديل فاتورة مُرحّلة: يُحدَّث القيد في مكانه ويُصحَّح المخزون بالفرق. */
    public function test_update_posted_adjusts_ledger_and_stock_in_place(): void
    {
        $before = $this->stock();
        $inv = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 50, 'tax_rate' => 0]],
        );
        $entryId = $inv->journal_entry_id;

        // تعديل: 4 قطع بتكلفة 25 ⇒ 100 بدل 500.
        $this->service->updatePosted($inv->fresh('items'),
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 4, 'unit_cost' => 25, 'tax_rate' => 0]],
        );

        $inv->refresh();
        $this->assertSame($entryId, $inv->journal_entry_id);   // نفس القيد لا قيد جديد
        $this->assertEqualsWithDelta(100, (float) $inv->total, 0.01);
        $this->assertEqualsWithDelta(100, $this->balance('1200'), 0.01);
        $this->assertEqualsWithDelta(100, $this->balance('2010'), 0.01);
        $this->assertEqualsWithDelta($before + 4, $this->stock(), 0.001); // 10 سُحبت ثم 4 دخلت
    }

    /** حذف فاتورة مُرحّلة: يُحذف القيد ويُسحب المخزون — لا أثر متبقٍّ. */
    public function test_delete_posted_removes_ledger_and_stock(): void
    {
        $beforeStock = $this->stock();
        $beforeInv = $this->balance('1200');
        $beforePayable = $this->balance('2010');

        $inv = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 6, 'unit_cost' => 30, 'tax_rate' => 0]],
        );
        $entryId = $inv->journal_entry_id;

        $this->service->deletePosted($inv->fresh('items'));

        $this->assertDatabaseMissing('journal_entries', ['id' => $entryId]);
        $this->assertEqualsWithDelta($beforeInv, $this->balance('1200'), 0.01);
        $this->assertEqualsWithDelta($beforePayable, $this->balance('2010'), 0.01);
        $this->assertEqualsWithDelta($beforeStock, $this->stock(), 0.001);
        $this->assertSoftDeleted('purchase_invoices', ['id' => $inv->id]);
    }

    /** الفاتورة المسدَّدة محميّة: لا تُعدَّل ولا تُحذف قبل عكس دفعاتها. */
    public function test_paid_invoice_is_protected_from_edit_and_delete(): void
    {
        $inv = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 2, 'unit_cost' => 100, 'tax_rate' => 0]],
        );
        $treasury = Treasury::where('is_active', true)->whereNotNull('gl_account_id')->firstOrFail();
        $this->service->pay($inv->fresh(), $treasury->id, 50);

        $this->expectException(ValidationException::class);
        $this->service->deletePosted($inv->fresh('items'));
    }
}
