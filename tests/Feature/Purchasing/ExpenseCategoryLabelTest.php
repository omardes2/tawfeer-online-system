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
 * وسم نوع الفاتورة بجانب رقمها: بضاعة · شحن بحري · تخليص · عمولة · نقل داخلي.
 *
 * فواتير الشحنة الواحدة تتشابه رقمًا ومورّدًا وتاريخًا، فبلا وسمٍ لا يُعرف ما
 * تخصّه إلا بفتح كلٍّ منها.
 */
class ExpenseCategoryLabelTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $invoices;

    private Supplier $supplier;

    private ImportShipment $shipment;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        $this->invoices = app(PurchaseInvoiceService::class);
        $this->supplier = Supplier::factory()->create();
        $this->shipment = app(ImportShipmentService::class)->create(['reference' => 'ZCSU6570438']);
    }

    private function expense(string $category, string $description): PurchaseInvoice
    {
        return $this->invoices->create([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'import_shipment_id' => $this->shipment->id,
            'kind' => 'expenses',
            'expense_category' => $category,
        ], [['description' => $description, 'qty' => 1, 'unit_cost' => 1000]]);
    }

    public function test_each_expense_kind_carries_its_own_label(): void
    {
        foreach (PurchaseInvoice::EXPENSE_CATEGORIES as $key => $label) {
            $this->assertSame(__($label), $this->expense($key, $label)->kindLabel());
        }
    }

    public function test_a_goods_invoice_is_labelled_goods_and_carries_no_category(): void
    {
        $invoice = $this->invoices->create([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'kind' => 'goods',
            // تصنيفٌ مُرسَل مع فاتورة بضاعة يُهمَل: وسمٌ كاذب في القائمة لولا ذلك.
            'expense_category' => 'customs',
        ], [['variant_id' => ProductVariant::factory()->create()->id, 'qty' => 1, 'unit_cost' => 50]]);

        $this->assertSame(__('بضاعة'), $invoice->kindLabel());
        $this->assertNull($invoice->expense_category);
    }

    /** تصنيف غير معروف لا يكسر الوسم — يسقط على «أخرى». */
    public function test_an_unknown_category_falls_back_to_other(): void
    {
        $invoice = $this->expense('sea_freight', 'شحن');
        $invoice->forceFill(['expense_category' => 'nonsense'])->save();

        $this->assertSame(__('مصاريف أخرى'), $invoice->fresh()->kindLabel());
    }

    public function test_the_list_shows_the_label_beside_each_number(): void
    {
        $this->expense('customs', 'تخليص جمركي');
        $this->expense('sea_freight', 'شحن بحري');

        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertSee(__('تخليص وجمارك'), false)
            ->assertSee(__('شحن بحري'), false);
    }

    /** الفاتورة المُرحّلة لا تُفتح للتعديل، فتصنيفُها يُعدَّل من صفحتها. */
    public function test_the_category_of_a_posted_invoice_can_be_corrected(): void
    {
        $invoice = $this->invoices->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'import_shipment_id' => $this->shipment->id,
            'kind' => 'expenses',
            'expense_category' => 'other',
        ], [['description' => 'رسوم', 'qty' => 1, 'unit_cost' => 3000]]);

        $this->post(route('admin.purchasing.invoices.classify', $invoice), [
            'import_shipment_id' => $this->shipment->id,
            'expense_category' => 'customs',
        ])->assertRedirect();

        $invoice->refresh();

        $this->assertSame('customs', $invoice->expense_category);
        $this->assertSame(3000.0, (float) $invoice->subtotal); // المبالغ لم تُمسّ
        $this->assertSame('posted', $invoice->status);
    }
}
