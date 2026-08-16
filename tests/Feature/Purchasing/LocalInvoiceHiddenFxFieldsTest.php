<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ImportShipmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الفاتورة المحلية تُحفظ رغم وصول حقلي الصرف صفرَين.
 *
 * حقول الاستيراد تبقى في صفحة الفاتورة حتى حين تكون العملة محلية (تُخفى ولا
 * تُحذف)، فيُرسلها المتصفّح `0` فتسقط على `gt:0` برسالتين إنجليزيتين على
 * حقلين لا يراهما المستخدم — فتعذّر حفظ أي فاتورة بالشيكل: بضاعةً كانت أو
 * مصاريفَ تخليص. الصفر هنا معناه «غير مُدخَل».
 */
class LocalInvoiceHiddenFxFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->supplier = Supplier::factory()->create();
    }

    /** ما يرسله النموذج فعليًّا: أصفار في حقول الاستيراد المخفيّة. */
    private function localPayload(array $overrides = []): array
    {
        return array_replace([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'currency' => 'ILS',
            'fx_rate_to_usd' => 0,
            'usd_rate' => 0,
            'commission_rate' => 0,
            'cbm_rate_usd' => 0,
            'items' => [[
                'variant_id' => ProductVariant::factory()->create()->id,
                'qty' => 3,
                'unit_cost' => 120,
                'tax_rate' => 0,
            ]],
        ], $overrides);
    }

    public function test_a_local_goods_invoice_saves_with_zeroed_fx_fields(): void
    {
        $this->post(route('admin.purchasing.invoices.store'), $this->localPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoice = PurchaseInvoice::latest('id')->firstOrFail();

        $this->assertSame('ILS', $invoice->currency);
        $this->assertSame(360.0, (float) $invoice->subtotal); // 3 × 120 بالشيكل مباشرة
        $this->assertFalse($invoice->isImport());
    }

    /** حالة المستخدم: فاتورة تخليص جمركي بالشيكل على كونتينر مستورد. */
    public function test_a_local_expense_invoice_saves_and_charges_the_accrual_account(): void
    {
        $shipment = app(ImportShipmentService::class)->create(['reference' => 'ZCSU6570438']);

        $this->post(route('admin.purchasing.invoices.store'), $this->localPayload([
            'kind' => 'expenses',
            'import_shipment_id' => $shipment->id,
            'items' => [[
                'description' => 'تخليص جمركي',
                'qty' => 1,
                'unit_cost' => 50000,
                'tax_rate' => 0,
            ]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $invoice = PurchaseInvoice::latest('id')->firstOrFail();

        $this->assertSame(PurchaseInvoice::KIND_EXPENSES, $invoice->kind);
        $this->assertSame($shipment->id, $invoice->import_shipment_id);
        $this->assertSame(50000.0, (float) $invoice->subtotal);
        // بند مصاريف: وصفٌ ومبلغ بلا صنف.
        $this->assertNull($invoice->items->first()->variant_id);
        $this->assertSame('تخليص جمركي', $invoice->items->first()->description);
    }

    /** ولا يُفقد التحقّق أثره: سعرٌ واحد بلا قرينه ما زال خطأً. */
    public function test_one_rate_without_the_other_is_still_rejected(): void
    {
        $this->post(route('admin.purchasing.invoices.store'), $this->localPayload([
            'currency' => 'USD',
            'usd_rate' => 3.65,
        ]))->assertSessionHasErrors('fx_rate_to_usd');
    }

    /** والفاتورة الأجنبية بسعرين موجبين تبقى استيرادًا كما كانت. */
    public function test_a_foreign_invoice_still_computes_as_an_import(): void
    {
        $this->post(route('admin.purchasing.invoices.store'), $this->localPayload([
            'currency' => 'CNY',
            'fx_rate_to_usd' => 7.15,
            'usd_rate' => 3.65,
            'items' => [[
                'variant_id' => ProductVariant::factory()->create()->id,
                'qty' => 10,
                'unit_price_foreign' => 45,
                'unit_cost' => 0,
                'tax_rate' => 0,
            ]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue(PurchaseInvoice::latest('id')->firstOrFail()->isImport());
    }
}
