<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ImportShipmentService;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمة فواتير الشراء تُظهر شحنة كل فاتورة وتُفلتر بها.
 *
 * ## لماذا
 *
 * الكونتينر الواحد يُنتج فواتير عدّة: البضاعة من مورّدها بالرنمينبي، والشحن
 * البحري والتخليص من مخلّصٍ آخر بالدولار، والنقل الداخلي بالشيكل. ولا تُدمج —
 * موردون وتواريخ وعملات مختلفة، وكلٌّ دائنٌ مستقلّ يُدفع وحده — لكنها تكلفةُ
 * شحنةٍ واحدة.
 *
 * والربط قائمٌ في البيانات (`import_shipment_id`) وفي شاشة الشحنة، لكنه لم يكن
 * ظاهرًا في القائمة: تُقرأ مستنداتٍ متفرّقة لا عائلةً واحدة.
 *
 * ## والفلتر يُصفّي الصفحة كلّها
 *
 * بطاقاتٌ تعدّ كل الفواتير فوق جدولٍ يعرض كونتينرًا واحدًا تُقرأ على أنها أرقامه.
 */
class InvoiceShipmentFilterTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    private Supplier $supplier;

    private ProductVariant $variant;

    private ImportShipment $shipment;

    private ImportShipment $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(PurchaseInvoiceService::class);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();

        $shipments = app(ImportShipmentService::class);
        $this->shipment = $shipments->create(['reference' => 'TEMU7550473']);
        $this->other = $shipments->create(['reference' => 'MSCU1112223']);
    }

    private function goods(?ImportShipment $shipment): PurchaseInvoice
    {
        return $this->service->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'import_shipment_id' => $shipment?->id,
        ], [['variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 25]]);
    }

    /** مصروف شحنة — مورّدٌ آخر وعملةٌ أخرى، ونفس الكونتينر. */
    private function expense(ImportShipment $shipment): PurchaseInvoice
    {
        return $this->service->createAndPost([
            'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => now()->toDateString(),
            'import_shipment_id' => $shipment->id,
            'kind' => 'expenses',
        ], [['description' => 'شحن بحري', 'qty' => 1, 'unit_cost' => 900]]);
    }

    // ────────── العمود ──────────

    /** **رقم الكونتينر في عمودٍ بجانب كل فاتورة.** */
    public function test_the_index_shows_the_shipment_number(): void
    {
        $this->goods($this->shipment);

        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertSee($this->shipment->number);
    }

    /** وفاتورةٌ بلا شحنة تُعرض بشرطة — والفراغ هنا معلومة لا نقص. */
    public function test_an_invoice_without_a_shipment_renders_a_dash(): void
    {
        $this->goods(null);

        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertSee('—');
    }

    // ────────── الفلتر ──────────

    /** **الفلتر يعرض فواتير الكونتينر وحدها** — البضاعة ومصاريفها معًا. */
    public function test_filtering_keeps_only_that_shipments_invoices(): void
    {
        $goods = $this->goods($this->shipment);
        $freight = $this->expense($this->shipment);
        $stranger = $this->goods($this->other);

        $this->get(route('admin.purchasing.invoices.index', ['shipment' => $this->shipment->id]))
            ->assertOk()
            ->assertSee($goods->number)
            ->assertSee($freight->number)
            ->assertDontSee($stranger->number);
    }

    /** **والبطاقات تتبع الفلتر** — عددٌ يخالف الجدول يُقرأ عطبًا. */
    public function test_the_cards_follow_the_filter(): void
    {
        $this->goods($this->shipment);
        $this->expense($this->shipment);
        $this->goods($this->other);

        $this->assertSame(3, $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()->viewData('totalCount'));

        $this->assertSame(2, $this->get(route('admin.purchasing.invoices.index', ['shipment' => $this->shipment->id]))
            ->assertOk()->viewData('totalCount'));
    }

    /** وعدّاد الحالات كذلك. */
    public function test_the_status_counts_follow_the_filter(): void
    {
        $this->goods($this->shipment);
        $this->goods($this->other);
        $this->goods($this->other);

        $counts = $this->get(route('admin.purchasing.invoices.index', ['shipment' => $this->shipment->id]))
            ->assertOk()->viewData('statusCounts');

        $this->assertSame(1, (int) $counts['posted']);
    }

    /** والحالة تُلازم الشحنة: «مُرحّلة» بعد الفلتر تعني مُرحّلات هذا الكونتينر. */
    public function test_the_status_filter_composes_with_the_shipment(): void
    {
        $posted = $this->goods($this->shipment);
        $stranger = $this->goods($this->other);

        $this->get(route('admin.purchasing.invoices.index', [
            'shipment' => $this->shipment->id, 'status' => 'posted',
        ]))
            ->assertOk()
            ->assertSee($posted->number)
            ->assertDontSee($stranger->number);
    }

    /** وشحنةٌ لا وجود لها تُتجاهَل بدل أن تُفرغ الصفحة. */
    public function test_an_unknown_shipment_is_ignored(): void
    {
        $invoice = $this->goods($this->shipment);

        $this->get(route('admin.purchasing.invoices.index', ['shipment' => 999999]))
            ->assertOk()
            ->assertSee($invoice->number);
    }

    /** والقائمة المنسدلة تحمل الكونتينرات بأرقام مرجعها. */
    public function test_the_picker_lists_the_shipments(): void
    {
        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertSee('TEMU7550473')
            ->assertSee('MSCU1112223');
    }
}
