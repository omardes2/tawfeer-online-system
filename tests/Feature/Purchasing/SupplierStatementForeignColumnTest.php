<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * كشف حساب المورد يحمل قيمة الفاتورة **بعملتها** بجانب أثرها بالشيكل.
 *
 * ## لماذا
 *
 * الكشف مبنيٌّ من دفتر الأستاذ، وحساب المورد بالشيكل — فكل أرقامه شيكل. وكشف
 * المورد الصيني مكتوبٌ بالرنمينبي. فمن جلس يطابق الكشفين كان يقارن عملتين، ولا
 * سبيل عنده إلى معرفة أن سطر ١٢٩٬٢٩١.٠٤ ₪ هو فاتورة ٢٨٤٬٩٥٠.٤٢ ¥ إلا بفتح كل
 * فاتورة على حدة.
 *
 * ## وسطورٌ ليست فواتير
 *
 * الدفعة والرصيد الافتتاحي وفرق الصرف لا قيمةَ لها بعملةٍ أجنبية — تبقى فارغة،
 * وذلك صحيحٌ لا ناقص. والربط بـ`journal_entry_id` لا بالوصف: القيد يعرف فاتورته.
 */
class SupplierStatementForeignColumnTest extends TestCase
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
        // عبر الخدمة لا المصنع: هي التي تفتح للمورد حسابه الفرعيّ، وبلا حسابٍ
        // يسقط الكشفُ إلى مسار الاحتياط من المستندات — فيبقى المسار الأصلي
        // (من دفتر الأستاذ) بلا اختبار.
        $this->supplier = app(SupplierService::class)->create(['name' => 'بضاعة الصين']);
        $this->variant = ProductVariant::factory()->create();

        $this->assertNotNull($this->supplier->gl_account_id, 'الاختبار يفترض حسابًا فرعيًّا للمورد.');
    }

    /** فاتورة استيراد بالرنمينبي: ١٠٠ × ١٠ ¥ = ١٬٠٠٠ ¥. */
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
            'variant_id' => $this->variant->id, 'qty' => 100,
            'unit_price_foreign' => 10, 'cbm_per_unit' => 0.01,
        ]]);
    }

    private function statement(): Collection
    {
        return $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()->viewData('statement');
    }

    /** @return array<string, mixed>|null */
    private function rowOfType(string $type): ?array
    {
        return $this->statement()->firstWhere('type', $type);
    }

    /** **سطر الفاتورة يحمل قيمتها بعملتها ورمزَها.** */
    public function test_an_invoice_row_carries_its_foreign_value(): void
    {
        $this->importInvoice();

        $row = $this->rowOfType('invoice');

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(1000, $row['foreign'], 0.01);
        $this->assertSame('CNY', $row['foreign_currency']);
    }

    /** ويظهر على الشاشة بالرقم والرمز. */
    public function test_the_page_shows_the_foreign_value(): void
    {
        $this->importInvoice();

        $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee(__('قيمة الفاتورة بعملتها'), false)
            ->assertSee('1,000.00')
            ->assertSee('¥', false);
    }

    /** **والدفعة لا قيمة أجنبية لها** — ليست فاتورة. */
    public function test_a_payment_row_has_no_foreign_value(): void
    {
        $this->importInvoice();

        // الحساب الفرعيّ للمورد لا حساب الذمم الأمّ: الكشف يُبنى من سطور حساب
        // المورد نفسه، فدفعةٌ تُقيَّد على 2010 العامّ لا تظهر في كشفه.
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create('payment', [
            'treasury_id' => Treasury::active()->firstOrFail()->id,
            'amount' => 100,
            'counter_account_id' => $this->supplier->glAccount()->firstOrFail()->id,
            'supplier_id' => $this->supplier->id,
            'description' => 'دفعة للمورد',
            'voucher_date' => now()->toDateString(),
        ]);
        $vouchers->post($vouchers->approve($voucher));

        $row = $this->rowOfType('payment');

        $this->assertNotNull($row);
        $this->assertNull($row['foreign']);
    }

    /** والرصيد الافتتاحي كذلك. */
    public function test_the_opening_row_has_no_foreign_value(): void
    {
        app(SupplierService::class)->syncOpeningBalance($this->supplier, 5000);
        $this->importInvoice();

        $row = $this->rowOfType('opening');

        $this->assertNotNull($row, 'سطر الرصيد الافتتاحي يجب أن يظهر في الكشف.');
        $this->assertNull($row['foreign']);
    }

    /** والفاتورة المحلّية تعرض قيمتها بالشيكل لا صفرًا. */
    public function test_a_local_invoice_shows_its_subtotal(): void
    {
        $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 4, 'unit_cost' => 250]],
        );

        $row = $this->rowOfType('invoice');

        $this->assertEqualsWithDelta(1000, $row['foreign'], 0.01);
        $this->assertSame('ILS', $row['foreign_currency']);
    }

    /** ومجموعٌ لكل عملة على حدة — ¥ و₪ لا يُجمعان في رقم. */
    public function test_the_footer_totals_each_currency_apart(): void
    {
        $this->importInvoice();
        $this->importInvoice();

        $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee(__('مجموع الفواتير بـ:c', ['c' => 'CNY']), false)
            ->assertSee('2,000.00');
    }
}
