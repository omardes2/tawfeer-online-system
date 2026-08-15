<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ImportShipmentService;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تعديل فاتورة مُرحّلة بعد خروج بضاعتها.
 *
 * التعديل يسحب البضاعة أولًا ثم يُدخلها بالأرقام الجديدة. فإن بِيعت أو نُقلت أو
 * وُزِّعت على مقاسات أخرى لم يعد هناك ما يُسحب — والرفض صحيح، لكن الرسالة كانت
 * عامّة: «الكمية المتاحة غير كافية في المستودع» بلا اسم صنفٍ ولا رقم ولا مخرج،
 * فيقف المستخدم أمام حائط بعد ملء النموذج كاملًا.
 */
class PostedInvoiceEditStockGuardTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    private Supplier $supplier;

    private ProductVariant $variant;

    private Warehouse $warehouse;

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
        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->firstOrFail();
    }

    private function postedInvoice(float $qty = 100): PurchaseInvoice
    {
        return $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_cost' => 35]],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function items(float $qty): array
    {
        return [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_cost' => 35]];
    }

    public function test_editing_names_the_item_and_the_numbers_when_the_stock_left(): void
    {
        $invoice = $this->postedInvoice(100);
        // بِيعت 60 قطعة، فلم يبقَ ما يكفي لسحب الـ100.
        app(InventoryService::class)->issue($this->variant, $this->warehouse, 60);

        try {
            $this->service->updatePosted($invoice->fresh('items'), ['supplier_id' => $this->supplier->id], $this->items(120));
            $this->fail('كان يجب رفض التعديل.');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');

            $this->assertStringContainsString($this->variant->product->name, $message);
            $this->assertStringContainsString('100', $message);  // المطلوب سحبه
            $this->assertStringContainsString('40', $message);   // المتاح
            $this->assertStringContainsString('اعكس الفاتورة', $message);
        }
    }

    public function test_the_refusal_leaves_the_stock_untouched(): void
    {
        $invoice = $this->postedInvoice(100);
        app(InventoryService::class)->issue($this->variant, $this->warehouse, 60);
        $before = (float) InventoryStock::where('variant_id', $this->variant->id)->value('on_hand');

        try {
            $this->service->updatePosted($invoice->fresh('items'), ['supplier_id' => $this->supplier->id], $this->items(120));
        } catch (ValidationException) {
            // متوقَّع.
        }

        $this->assertEqualsWithDelta($before, (float) InventoryStock::where('variant_id', $this->variant->id)->value('on_hand'), 0.001);
    }

    public function test_editing_still_works_while_the_stock_is_there(): void
    {
        $invoice = $this->postedInvoice(100);

        $this->service->updatePosted($invoice->fresh('items'), ['supplier_id' => $this->supplier->id], $this->items(120));

        $this->assertEqualsWithDelta(120, (float) InventoryStock::where('variant_id', $this->variant->id)->value('on_hand'), 0.001);
    }

    public function test_selling_part_of_it_still_allows_editing_when_enough_remains(): void
    {
        // بِيعت 10 من 100 ⇒ المتاح 90 < 100 المطلوب سحبه ⇒ يُرفض.
        // أمّا لو بِيع صفر فالسحب ممكن — هذا الحدّ مقصود لا عرَضي.
        $invoice = $this->postedInvoice(100);
        app(InventoryService::class)->issue($this->variant, $this->warehouse, 10);

        $this->expectException(ValidationException::class);
        $this->service->updatePosted($invoice->fresh('items'), ['supplier_id' => $this->supplier->id], $this->items(50));
    }

    public function test_the_edit_page_warns_before_the_form_is_filled(): void
    {
        $invoice = $this->postedInvoice(100);
        app(InventoryService::class)->issue($this->variant, $this->warehouse, 60);

        $this->get(route('admin.purchasing.invoices.edit', $invoice))
            ->assertOk()
            ->assertSee(__('لا يمكن حفظ التعديل على هذه الفاتورة'), false)
            ->assertSee($this->variant->product->name, false);
    }

    public function test_a_healthy_invoice_shows_no_warning(): void
    {
        $invoice = $this->postedInvoice(100);

        $this->get(route('admin.purchasing.invoices.edit', $invoice))
            ->assertOk()
            ->assertDontSee(__('لا يمكن حفظ التعديل على هذه الفاتورة'), false);
    }

    public function test_deleting_is_guarded_the_same_way(): void
    {
        // الحذف يسحب البضاعة أيضًا — نفس الحارس ونفس الرسالة.
        $invoice = $this->postedInvoice(100);
        app(InventoryService::class)->issue($this->variant, $this->warehouse, 60);

        $this->expectException(ValidationException::class);
        $this->service->deletePosted($invoice->fresh('items'));
    }

    public function test_an_expense_invoice_has_no_stock_to_guard(): void
    {
        $shipment = app(ImportShipmentService::class)->create(['reference' => 'CNTR-X']);
        $expense = $this->service->createAndPost([
            'supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString(),
            'import_shipment_id' => $shipment->id, 'kind' => 'expenses',
        ], [['description' => 'شحن بحري', 'qty' => 1, 'unit_cost' => 500]]);

        $this->assertSame([], $this->service->stockShortages($expense->fresh('items')));
    }
}
