<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بطاقات المورد بعملاته إلى جانب الشيكل.
 *
 * ## رقمان مختلفان في طبيعتهما
 *
 * **المشتريات بعملة الفاتورة مجموعٌ حقيقي**: كل فاتورة تحمل قيمتها بعملتها
 * محفوظةً منذ إنشائها، فتُجمع كما هي — رقمٌ يُطابَق بكشف المورد سطرًا سطرًا.
 *
 * **والمدفوعات والرصيد تحويلٌ تقديري**: كلاهما مبلغٌ بالشيكل تراكم من فواتير
 * بأسعار صرفٍ مختلفة، فلا سعرَ واحد يخصّه. ويُحوَّلان بمعدّلٍ موزون **من فواتير
 * هذا المورد نفسه** — لا من إعدادٍ عام ولا من سعر اليوم — ويُوسَمان بـ«≈».
 *
 * وخلطُ الاثنين بلا تمييز يجعل تقديرًا يُقرأ التزامًا دقيقًا.
 */
class SupplierCardsForeignTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $invoices;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->invoices = app(PurchaseInvoiceService::class);
        $this->supplier = app(SupplierService::class)->create(['name' => 'بضاعة الصين']);
        $this->variant = ProductVariant::factory()->create();
    }

    /** فاتورة بالرنمينبي: ١٠٠ × ١٠ ¥ = ١٬٠٠٠ ¥، بسعر ٧ ¥/$ و٣.٧ ₪/$. */
    private function importInvoice(float $qty = 100, float $price = 10): PurchaseInvoice
    {
        return $this->invoices->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'currency' => 'CNY',
            'fx_rate_to_usd' => 7.0,
            'usd_rate' => 3.7,
        ], [[
            'variant_id' => $this->variant->id, 'qty' => $qty,
            'unit_price_foreign' => $price, 'cbm_per_unit' => 0,
        ]]);
    }

    /** @return array<string, mixed> */
    private function cards(): array
    {
        return $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()->viewData('foreign');
    }

    /** **المشتريات بالرنمينبي مجموعٌ حقيقي** — لا تحويل ولا تقدير. */
    public function test_purchases_in_foreign_are_a_true_sum(): void
    {
        $this->importInvoice();
        $this->importInvoice();

        $foreign = $this->cards();

        $this->assertSame('CNY', $foreign['currency']);
        $this->assertEqualsWithDelta(2000, $foreign['invoiced_foreign'], 0.01);
    }

    /** **والرصيد يظهر بالرنمينبي والدولار** تحت الشيكل. */
    public function test_the_balance_carries_both_currencies(): void
    {
        $this->importInvoice();   // 1,000 ¥ = 142.86 $ = 528.57 ₪

        $foreign = $this->cards();

        $this->assertEqualsWithDelta(1000, $foreign['balance_foreign'], 1.0);
        $this->assertEqualsWithDelta(142.86, $foreign['balance_usd'], 1.0);
    }

    /** والسعر المشتقّ من فواتير المورد نفسه: ٣.٧ ₪/$ و٠.٥٢٨٦ ₪/¥. */
    public function test_the_rate_is_derived_from_the_suppliers_own_invoices(): void
    {
        $this->importInvoice();

        $foreign = $this->cards();

        $this->assertEqualsWithDelta(3.7, $foreign['ils_per_usd'], 0.01);
        $this->assertEqualsWithDelta(0.5286, $foreign['ils_per_foreign'], 0.01);
    }

    /** والشاشة تعرض الثلاثة، والتقديري موسومٌ بـ«≈». */
    public function test_the_page_shows_all_three_and_marks_the_estimates(): void
    {
        $this->importInvoice();

        $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('528.57')     // الشيكل — محفوظ كما كان
            ->assertSee('1,000.00')   // الرنمينبي
            ->assertSee('≈', false)
            ->assertSee('¥', false);
    }

    /** **ومورّدٌ بلا فاتورة أجنبية يبقى بالشيكل وحده** — لا صفرٌ ولا سعرٌ مخترع. */
    public function test_a_local_only_supplier_stays_in_shekels(): void
    {
        $this->invoices->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 4, 'unit_cost' => 250]],
        );

        $this->assertSame([], $this->cards());

        $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('1,000.00')
            ->assertDontSee('≈', false);
    }

    /** والفاتورة غير المُرحّلة لا تدخل المجموع الأجنبي. */
    public function test_an_unposted_invoice_is_excluded(): void
    {
        $posted = $this->importInvoice();
        $this->invoices->reverse($posted->fresh('items'));

        $this->assertSame([], $this->cards());
    }
}
