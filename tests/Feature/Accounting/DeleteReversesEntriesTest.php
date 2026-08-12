<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\OrderVoidService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حذف المستندات المُرحّلة **يعكس** قيودها ولا يحذفها (ADR-016): الأثر المالي صفر
 * كما في الحذف، لكن الدفتر يحتفظ بالقيد وعكسه — فلا فجوات في ترقيم القيود ويبقى
 * سبب تغيّر الأرصدة مقروءًا للمدقّق.
 */
class DeleteReversesEntriesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function balance(string $code): float
    {
        return app(AccountingService::class)->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    // ---- طلب البيع ----

    private function postedOrder(): Order
    {
        $product = Product::factory()->active()->create(['retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 10, 60);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 2, 'unit_price' => 100, 'discount' => 0]], 2026);

        app(OrderService::class)->fulfillToShipped($order);

        return $order->fresh();
    }

    public function test_order_delete_reverses_entries_and_keeps_them_in_ledger(): void
    {
        $order = $this->postedOrder();
        $revenueId = $order->revenue_entry_id;
        $cogsId = $order->cogs_entry_id;
        $this->assertNotNull($revenueId);

        $entriesBefore = JournalEntry::count();
        $receivableBefore = $this->balance('1050');
        $revenueBefore = $this->balance('4010');

        app(OrderVoidService::class)->void($order, User::factory()->create());

        // الأثر المالي صفر: الأرصدة عادت لما قبل البيع.
        $this->assertEqualsWithDelta($receivableBefore - 200, $this->balance('1050'), 0.01);
        $this->assertEqualsWithDelta($revenueBefore - 200, $this->balance('4010'), 0.01);

        // القيدان الأصليان **باقيان** ومعهما قيدان عاكسان (لا حذف، لا فجوات).
        $this->assertNotNull(JournalEntry::find($revenueId));
        $this->assertNotNull(JournalEntry::find($cogsId));
        $this->assertEquals($entriesBefore + 2, JournalEntry::count());
        $this->assertEquals(1, JournalEntry::where('reverses_entry_id', $revenueId)->count());
        $this->assertEquals(1, JournalEntry::where('reverses_entry_id', $cogsId)->count());
    }

    /** الأرقام متسلسلة بلا فجوة: القيد الأصلي باقٍ في الدفتر بعد الحذف. */
    public function test_order_delete_leaves_no_gap_in_entry_numbers(): void
    {
        $order = $this->postedOrder();
        $numbersBefore = JournalEntry::orderBy('id')->pluck('number');

        app(OrderVoidService::class)->void($order, User::factory()->create());

        $numbersAfter = JournalEntry::orderBy('id')->pluck('number');
        // كل رقم كان موجودًا ما زال موجودًا (أُضيف إليه العاكسان فقط).
        $this->assertEmpty($numbersBefore->diff($numbersAfter));
    }

    // ---- فاتورة المشتريات ----

    public function test_purchase_invoice_delete_reverses_entry(): void
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;

        $invoice = app(PurchaseInvoiceService::class)->createAndPost(
            ['supplier_id' => $supplier->id, 'invoice_date' => '2026-08-01', 'warehouse_id' => $this->warehouse->id],
            [['variant_id' => $variant->id, 'qty' => 5, 'unit_cost' => 20]],
        );

        $entryId = $invoice->journal_entry_id;
        $this->assertNotNull($entryId);
        $payableBefore = $this->balance('2010');

        app(PurchaseInvoiceService::class)->deletePosted($invoice);

        // الأثر صفر، والقيد الأصلي باقٍ ومعه عاكسه.
        $this->assertEqualsWithDelta($payableBefore - 100, $this->balance('2010'), 0.01);
        $this->assertNotNull(JournalEntry::find($entryId));
        $this->assertEquals(1, JournalEntry::where('reverses_entry_id', $entryId)->count());
        $this->assertSoftDeleted('purchase_invoices', ['id' => $invoice->id]);
    }
}
