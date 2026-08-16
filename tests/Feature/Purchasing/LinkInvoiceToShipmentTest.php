<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ImportShipmentService;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إسناد فاتورة قائمة إلى شحنة استيراد بلا تعديلها.
 *
 * كان المسار الوحيد «تعديل الفاتورة»، وهو يعكس المخزون ويعيد إدخاله: فمتى بِيع
 * جزء من بضاعة الكونتينر رُفض الحفظ («الكمية المتاحة غير كافية»)، وبقيت مصاريف
 * الاستيراد المستحقّة في تلك الفاتورة بلا شحنة تُنسب إليها — فلا يُحتسب فرق
 * التقدير ولا تُغلق الشحنة. الإسناد حقلٌ مرجعي لا إعادةَ تسعير، فلا يمسّ شيئًا.
 */
class LinkInvoiceToShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = [
        'fx_rate_to_usd' => 7.15,
        'usd_rate' => 3.65,
        'commission_rate' => 5,
        'cbm_rate_usd' => 180,
        'currency' => 'CNY',
    ];

    private PurchaseInvoiceService $invoices;

    private ImportShipmentService $shipments;

    private Supplier $supplier;

    private ProductVariant $variant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        $this->invoices = app(PurchaseInvoiceService::class);
        $this->shipments = app(ImportShipmentService::class);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();
    }

    /** فاتورة بضاعة مُرحّلة بلا شحنة — حالة الكونتينر المُدخَل قبل وجود الشحنات. */
    private function postedGoodsInvoice(): PurchaseInvoice
    {
        return $this->invoices->createAndPost(
            [
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'kind' => 'goods',
            ] + self::HEAD,
            [['variant_id' => $this->variant->id, 'qty' => 200, 'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012]],
        );
    }

    private function link(PurchaseInvoice $invoice, ?ImportShipment $shipment)
    {
        return $this->post(route('admin.purchasing.invoices.classify', $invoice), [
            'import_shipment_id' => $shipment?->id,
        ]);
    }

    /** الحالة المُبلَّغ عنها: بضاعة الفاتورة بِيعت، ومع ذلك يتمّ الإسناد. */
    public function test_a_posted_invoice_links_even_after_its_goods_were_sold(): void
    {
        $invoice = $this->postedGoodsInvoice();
        $shipment = $this->shipments->create(['reference' => 'ZCSU6570438']);
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();

        // بيع كل الكمية تقريبًا: التعديل العادي يرفض بعدها.
        app(InventoryService::class)->issue($this->variant, $warehouse, 199);

        $this->link($invoice, $shipment)->assertRedirect();

        $this->assertSame($shipment->id, $invoice->fresh()->import_shipment_id);
    }

    /** الإسناد لا يمسّ البنود ولا المخزون ولا القيد — هذا كل جوهر المسار. */
    public function test_linking_touches_neither_stock_nor_items_nor_the_journal_entry(): void
    {
        $invoice = $this->postedGoodsInvoice();
        $shipment = $this->shipments->create([]);
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();

        $before = [
            'stock' => (float) InventoryStock::where('variant_id', $this->variant->id)
                ->where('warehouse_id', $warehouse->id)->value('qty_on_hand'),
            'cost' => (float) $this->variant->fresh()->average_cost,
            'items' => $invoice->items()->get(['variant_id', 'qty', 'unit_cost', 'landed_unit_cost'])->toArray(),
            'entry' => $invoice->journal_entry_id,
            'total' => (float) $invoice->total,
        ];

        $this->link($invoice, $shipment)->assertRedirect();
        $invoice->refresh();

        $this->assertSame($before['stock'], (float) InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $warehouse->id)->value('qty_on_hand'));
        $this->assertSame($before['cost'], (float) $this->variant->fresh()->average_cost);
        $this->assertSame($before['items'], $invoice->items()->get(['variant_id', 'qty', 'unit_cost', 'landed_unit_cost'])->toArray());
        $this->assertSame($before['entry'], $invoice->journal_entry_id);
        $this->assertSame($before['total'], (float) $invoice->total);
        $this->assertSame('posted', $invoice->status);
    }

    /** وبعد الإسناد تدخل الفاتورة في ملخّص الشحنة، فيُحتسب فرق التقدير. */
    public function test_the_linked_invoice_feeds_the_shipment_summary(): void
    {
        $invoice = $this->postedGoodsInvoice();
        $shipment = $this->shipments->create([]);

        $this->assertSame(0.0, $this->shipments->summary($shipment)['accrued']);

        $this->link($invoice, $shipment);

        $this->assertSame(
            round($invoice->fresh()->importDifference(), 2),
            $this->shipments->summary($shipment->fresh())['accrued'],
        );
    }

    /** فاتورة مصاريف مُرحّلة على الشحنة (الإغلاق يشترط فاتورة واحدة على الأقل). */
    private function postedExpenseInvoice(ImportShipment $shipment, float $usd = 500): PurchaseInvoice
    {
        return $this->invoices->createAndPost(
            [
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'import_shipment_id' => $shipment->id,
                'kind' => 'expenses',
                'currency' => 'USD',
                'fx_rate_to_usd' => 1,
                'usd_rate' => 3.65,
            ],
            [['description' => 'شحن بحري', 'qty' => 1, 'unit_price_foreign' => $usd, 'unit_cost' => 0]],
        );
    }

    /** شحنة مُغلقة: فرقها أُقفل بقيد، فإدخال فاتورة إليها يزوّر رقمًا مستقرًّا. */
    public function test_a_closed_shipment_refuses_new_links(): void
    {
        $invoice = $this->postedGoodsInvoice();
        $shipment = $this->shipments->create([]);
        $this->postedExpenseInvoice($shipment);
        $this->shipments->close($shipment->fresh());

        $this->link($invoice, $shipment->fresh())->assertSessionHasErrors('import_shipment_id');

        $this->assertNull($invoice->fresh()->import_shipment_id);
    }

    /** فاتورة المصاريف بلا شحنة لا معنى لها — مصاريفُ ماذا؟ */
    public function test_an_expense_invoice_cannot_be_unlinked(): void
    {
        $shipment = $this->shipments->create([]);
        $expense = $this->postedExpenseInvoice($shipment);

        $this->link($expense, null)->assertSessionHasErrors('import_shipment_id');

        $this->assertSame($shipment->id, $expense->fresh()->import_shipment_id);
    }

    /** الإسناد صلاحية إدارة الشحنات لا مجرّد عرض الفواتير. */
    public function test_the_action_needs_the_shipment_manage_permission(): void
    {
        $invoice = $this->postedGoodsInvoice();
        $shipment = $this->shipments->create([]);

        $warehouse = User::factory()->create(['branch_id' => Branch::default()->id]);
        $warehouse->assignRole('warehouse'); // يرى الشحنات ولا يديرها.

        $this->actingAs($warehouse);
        $this->link($invoice, $shipment)->assertForbidden();

        $this->assertNull($invoice->fresh()->import_shipment_id);
    }
}
