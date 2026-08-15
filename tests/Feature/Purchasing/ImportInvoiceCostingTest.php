<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فاتورة استيراد — المرحلة ١: العملات والتكلفة الشاملة.
 *
 * المثال المرجعي: مورد صيني، الرمبي 7.15 للدولار، الدولار 3.65 شيكلًا، عمولة 5٪،
 * المتر المكعّب 180 $. البند: 200 قطعة بسعر 45 ¥ وحجم 0.012 م³ للوحدة.
 *
 * حدّ هذه المرحلة: الحساب والحفظ والعرض. الترحيل المحاسبي **لا يتغيّر** — يبقى
 * بذمّة المورد لا بالتكلفة الشاملة؛ ذلك انتقالُ المرحلة ٢.
 */
class ImportInvoiceCostingTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = [
        'fx_rate_to_usd' => 7.15,
        'usd_rate' => 3.65,
        'commission_rate' => 5,
        'cbm_rate_usd' => 180,
        'currency' => 'CNY',
    ];

    private PurchaseInvoiceService $service;

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
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $head
     * @param  array<int, array<string, mixed>>  $items
     */
    private function invoice(array $head, array $items): PurchaseInvoice
    {
        return $this->service->create(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()] + $head,
            $items,
        );
    }

    private function importInvoice(array $overrides = []): PurchaseInvoice
    {
        return $this->invoice(self::HEAD, [array_merge([
            'variant_id' => $this->variant->id,
            'qty' => 200,
            'unit_price_foreign' => 45,
            'cbm_per_unit' => 0.012,
        ], $overrides)]);
    }

    public function test_the_real_unit_cost_is_derived_from_the_exchange_rates(): void
    {
        $item = $this->importInvoice()->items->first();

        // 45 ¥ ÷ 7.15 × 3.65 = 22.9720 ₪ — ذمّة المورد، بلا مصاريف.
        $this->assertEqualsWithDelta(22.9720, (float) $item->unit_cost, 0.0005);
        $this->assertEqualsWithDelta(45, (float) $item->unit_price_foreign, 0.0001);
    }

    public function test_the_landed_unit_cost_adds_commission_and_freight(): void
    {
        $item = $this->importInvoice()->items->first();

        // (6.2937 + 0.3147 + 2.16) × 3.65 = 32.0047 ₪
        $this->assertEqualsWithDelta(32.0047, (float) $item->landed_unit_cost, 0.0005);
        $this->assertEqualsWithDelta(6400.93, (float) $item->landed_line_total, 0.02);
        $this->assertFalse((bool) $item->landed_is_manual);
    }

    public function test_the_invoice_totals_cover_the_three_currencies(): void
    {
        $invoice = $this->importInvoice();

        $this->assertEqualsWithDelta(9000, (float) $invoice->foreign_subtotal, 0.01);   // 200 × 45 ¥
        $this->assertEqualsWithDelta(4594.40, (float) $invoice->subtotal, 0.05);        // ذمّة المورد بالشيكل
        $this->assertEqualsWithDelta(6400.93, (float) $invoice->landed_subtotal, 0.05); // قيمة المخزون
        $this->assertEqualsWithDelta(2.4, (float) $invoice->total_cbm, 0.0001);         // 200 × 0.012
        $this->assertEqualsWithDelta(1806.53, $invoice->importDifference(), 0.05);      // المصاريف المحمّلة
        $this->assertTrue($invoice->isImport());
    }

    public function test_the_header_rates_are_stored_on_the_invoice(): void
    {
        $invoice = $this->importInvoice();

        $this->assertEqualsWithDelta(7.15, (float) $invoice->fx_rate_to_usd, 0.000001);
        $this->assertEqualsWithDelta(3.65, (float) $invoice->usd_rate, 0.000001);
        $this->assertEqualsWithDelta(5, (float) $invoice->commission_rate, 0.001);
        $this->assertEqualsWithDelta(180, (float) $invoice->cbm_rate_usd, 0.0001);
        $this->assertSame('CNY', $invoice->currency);
    }

    public function test_a_manually_written_landed_cost_is_kept_as_written(): void
    {
        // القرار للمستخدم: ما يكتبه بيده لا تدهسه الآلة الحاسبة.
        $item = $this->importInvoice(['landed_is_manual' => true, 'landed_unit_cost' => 40])->items->first();

        $this->assertEqualsWithDelta(40, (float) $item->landed_unit_cost, 0.0001);
        $this->assertTrue((bool) $item->landed_is_manual);
        // والسعر الحقيقي يبقى مشتقًّا من الصرف — التعديل اليدوي للتكلفة وحدها.
        $this->assertEqualsWithDelta(22.9720, (float) $item->unit_cost, 0.0005);
        $this->assertEqualsWithDelta(8000, (float) $item->landed_line_total, 0.01);
    }

    public function test_the_volume_falls_back_to_the_variant_card(): void
    {
        $this->variant->update(['cbm' => 0.035]);

        $item = $this->importInvoice(['cbm_per_unit' => 0])->items->first();

        $this->assertEqualsWithDelta(0.035, (float) $item->cbm_per_unit, 0.0001);
        // الفرق عن السعر الحقيقي = العمولة + الشحن بحجم كرت الصنف.
        $commission = 45 / 7.15 * 0.05;
        $this->assertEqualsWithDelta(
            ($commission + 0.035 * 180) * 3.65,
            (float) $item->landed_unit_cost - (float) $item->unit_cost,
            0.01,
        );
    }

    public function test_the_volume_falls_back_to_the_product_card(): void
    {
        $product = Product::factory()->create(['cbm' => 0.05]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cbm' => null]);

        $item = $this->importInvoice(['variant_id' => $variant->id, 'cbm_per_unit' => 0])->items->first();

        $this->assertEqualsWithDelta(0.05, (float) $item->cbm_per_unit, 0.0001);
    }

    public function test_a_volume_written_on_the_line_beats_the_card(): void
    {
        $this->variant->update(['cbm' => 0.035]);

        $item = $this->importInvoice(['cbm_per_unit' => 0.012])->items->first();

        $this->assertEqualsWithDelta(0.012, (float) $item->cbm_per_unit, 0.0001);
    }

    public function test_a_local_invoice_behaves_exactly_as_before(): void
    {
        // جوهر التوافق الرجعي: بلا أسعار صرف، التكلفة هي ما يُكتب — لا تحويل ولا مصاريف.
        $invoice = $this->invoice([], [[
            'variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 50, 'tax_rate' => 15,
        ]]);
        $item = $invoice->items->first();

        $this->assertFalse($invoice->isImport());
        $this->assertEqualsWithDelta(50, (float) $item->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(500, (float) $item->line_total, 0.01);
        $this->assertEqualsWithDelta(75, (float) $item->tax_amount, 0.01);
        $this->assertEqualsWithDelta(500, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(575, (float) $invoice->total, 0.01);
        // والتكلفة الشاملة تساوي السعر نفسه — لا فرق يُحمَّل.
        $this->assertEqualsWithDelta(50, (float) $item->landed_unit_cost, 0.0001);
        $this->assertSame(0.0, $invoice->importDifference());
        $this->assertNull($invoice->fx_rate_to_usd);
    }

    public function test_a_lone_exchange_rate_does_not_activate_the_calculator(): void
    {
        // سعر صرف واحد لا يكفي للتحويل — تُعامَل الفاتورة كمحلية بدل إنتاج رقم خاطئ.
        $invoice = $this->invoice(['fx_rate_to_usd' => 7.15], [[
            'variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 50, 'unit_price_foreign' => 45,
        ]]);

        $this->assertFalse($invoice->isImport());
        $this->assertEqualsWithDelta(50, (float) $invoice->items->first()->unit_cost, 0.0001);
    }

    public function test_the_journal_still_posts_the_supplier_price_not_the_landed_cost(): void
    {
        // حدّ المرحلة ١: المحاسبة كما هي. المخزون يُدين بذمّة المورد، والفرق لم يُقيَّد بعد.
        $accounting = app(AccountingService::class);
        $inventoryCode = config('accounting.purchasing.inventory_account');
        $before = $accounting->accountBalance(Account::where('code', $inventoryCode)->firstOrFail());

        $invoice = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()] + self::HEAD,
            [['variant_id' => $this->variant->id, 'qty' => 200, 'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012]],
        );

        $after = $accounting->accountBalance(Account::where('code', $inventoryCode)->firstOrFail());

        $this->assertSame('posted', $invoice->status);
        $this->assertEqualsWithDelta((float) $invoice->subtotal, $after - $before, 0.02);
        $this->assertNotEqualsWithDelta((float) $invoice->landed_subtotal, $after - $before, 0.02);
    }

    public function test_editing_an_invoice_recomputes_both_costs(): void
    {
        $invoice = $this->importInvoice();

        $this->service->update($invoice, ['cbm_rate_usd' => 90] + self::HEAD, [[
            'variant_id' => $this->variant->id, 'qty' => 200, 'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012,
        ]]);
        $item = $invoice->fresh('items')->items->first();

        // نصف تكلفة الشحن ⇒ (6.2937 + 0.3147 + 1.08) × 3.65 = 28.0626 ₪
        $this->assertEqualsWithDelta(28.0626, (float) $item->landed_unit_cost, 0.0005);
        $this->assertEqualsWithDelta(22.9720, (float) $item->unit_cost, 0.0005);
    }

    public function test_the_form_sends_the_import_header_and_columns(): void
    {
        $this->get(route('admin.purchasing.invoices.create'))
            ->assertOk()
            ->assertSee('fx_rate_to_usd', false)
            ->assertSee('usd_rate', false)
            ->assertSee('cbm_rate_usd', false)
            ->assertSee(__('التكلفة الشاملة'), false);
    }

    public function test_the_show_page_reveals_the_landed_summary(): void
    {
        $invoice = $this->importInvoice();

        $this->get(route('admin.purchasing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee(__('بيانات الاستيراد'), false)
            ->assertSee(__('مصاريف محمّلة'), false);
    }

    public function test_exchange_rates_are_refused_on_a_base_currency_invoice(): void
    {
        // تحويل الشيكل إلى الشيكل عبر الدولار يُنتج رقمًا خاطئًا بصمت — يُمنع صراحة.
        $this->from(route('admin.purchasing.invoices.create'))
            ->post(route('admin.purchasing.invoices.store'), [
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'currency' => 'ILS',
                'fx_rate_to_usd' => 7.15,
                'usd_rate' => 3.65,
                'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_cost' => 10]],
            ])
            ->assertSessionHasErrors('fx_rate_to_usd');
    }

    public function test_one_exchange_rate_without_the_other_is_refused(): void
    {
        $this->from(route('admin.purchasing.invoices.create'))
            ->post(route('admin.purchasing.invoices.store'), [
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'currency' => 'CNY',
                'usd_rate' => 3.65,
                'items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_cost' => 10]],
            ])
            ->assertSessionHasErrors('fx_rate_to_usd');
    }

    public function test_the_product_card_stores_the_volume(): void
    {
        $product = Product::factory()->create();

        $this->put(route('admin.products.update', $product), [
            'category_id' => $product->category_id,
            'unit_id' => $product->unit_id,
            'name' => $product->name,
            'cbm' => 0.0125,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(0.0125, (float) $product->fresh()->cbm, 0.0001);
        // ويُزامَن مع المتغيّر الافتراضي — توزيع الشحن يقرأ حجم المتغيّر أولًا.
        $this->assertEqualsWithDelta(0.0125, (float) $product->fresh()->defaultVariant->cbm, 0.0001);
    }
}
