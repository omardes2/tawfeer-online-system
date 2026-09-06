<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمة فواتير الشراء تعرض **ذمّة المورد بعملته**، لا الإجمالي بالشيكل.
 *
 * ## لماذا
 *
 * `total` قيمةُ البضاعة بالشيكل بتكلفتها **الشاملة**: سعر المورد + عمولة المكتب
 * + الشحن. والمورد لا يطالب بالعمولة ولا بالشحن — يطالب بما كتبه في فاتورته
 * بعملته. فمن فتح القائمة ليطابق كشف المورد وجد فرقًا في كل سطر، وفرقًا يكبر
 * كلّما زاد الشحن.
 *
 * وعمود «المتبقّي» أُزيل: الدفعات تُتابَع من كشف حساب المورد، وتكراره هنا بعملةٍ
 * ثالثة (الشيكل) بجانب ذمّةٍ بالرنمينبي يُقرأ طرحًا وهو ليس كذلك.
 */
class InvoiceIndexSupplierDueTest extends TestCase
{
    use RefreshDatabase;

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

    /** فاتورة استيراد بالرنمينبي: ١٠٠ وحدة × ١٠ ¥، وشحنٌ يرفع التكلفة الشاملة. */
    private function importInvoice(): PurchaseInvoice
    {
        return $this->service->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'currency' => 'CNY',
            'fx_rate_to_usd' => 7.0,
            'usd_rate' => 3.7,
            'commission_rate' => 5,
            'cbm_rate_usd' => 100,
        ], [[
            'variant_id' => $this->variant->id,
            'qty' => 100,
            'unit_price_foreign' => 10,
            'cbm_per_unit' => 0.01,
        ]]);
    }

    /** **الذمّة بالرنمينبي تظهر** — وهي ما يطالب به المورد. */
    public function test_the_supplier_due_is_shown_in_the_invoice_currency(): void
    {
        $invoice = $this->importInvoice();

        $this->assertEqualsWithDelta(1000, $invoice->supplierDueForeign(), 0.01);

        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertSee('1,000.00')
            ->assertSee('¥', false);
    }

    /** **والتكلفة الشاملة بالشيكل لا تظهر** — وهي ما كان يُعرض ولا يطابق كشفه. */
    public function test_the_landed_shekel_total_is_not_shown(): void
    {
        $invoice = $this->importInvoice();

        // المقارنة بالشيكل مع الشيكل: الشاملة تزيد على سعر المورد بالعمولة
        // والشحن. ومقارنةُ ١٠٠٠ ¥ بـ٥٢٨ ₪ لا تقول شيئًا — عملتان لا رقمان.
        $this->assertGreaterThan((float) $invoice->subtotal, (float) $invoice->landed_subtotal);

        // فاتورةٌ ثانية تُبعد بطاقةَ «المستحقّ للموردين» عن الرقم المفحوص:
        // البطاقة تجمع الإجماليّات بالشيكل عن حقّ، فلو ساوى مجموعُها إجماليَّ
        // فاتورةٍ واحدة لالتقطها الفحص وظنّها سطرَ الجدول.
        $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 3, 'unit_cost' => 77]],
        );

        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertDontSee(number_format((float) $invoice->total, 2))
            ->assertDontSee(__('الإجمالي'), false);
    }

    /** وعمود «المتبقّي» أُزيل. */
    public function test_the_balance_due_column_is_gone(): void
    {
        $this->importInvoice();

        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertDontSee(__('المتبقّي'), false)
            ->assertSee(__('ذمّة المورد'), false);
    }

    /**
     * والفاتورة المحلّية تعرض `subtotal` لا صفرًا.
     *
     * `foreign_subtotal` فيها صفر لأنها بلا عملة أجنبية، وعرضُه كان سيُظهر ذمّةً
     * معدومة لفاتورةٍ قائمة.
     */
    public function test_a_local_invoice_falls_back_to_its_subtotal(): void
    {
        $invoice = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 4, 'unit_cost' => 250]],
        );

        $this->assertEqualsWithDelta(1000, $invoice->supplierDueForeign(), 0.01);

        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertSee('1,000.00');
    }
}
