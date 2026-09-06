<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة الفاتورة المحلية تُفتح بكل أشكال بنودها.
 *
 * ## الثغرة التي تسدّها
 *
 * الاختباران الوحيدان اللذان كانا يفتحان هذه الصفحة يبنيان فاتورة **استيراد**.
 * والصفحة تتفرّع على `isImport()` في ستة مواضع — أعمدةٌ وبطاقاتٌ وقسمةٌ على سعر
 * الدولار — فالمسار المحليّ، وهو الأكثر استعمالًا، كان بلا حارس.
 *
 * ## والبنود بلا صنف خصوصًا
 *
 * بندٌ بوصفٍ حرّ (أجور تخليص) وبندٌ يُنشئ صنفًا جديدًا يتركان `variant_id`
 * فارغًا أو مستحدَثًا، والصفحة تقرأ `variant->product->name` — فسلسلةٌ آمنةٌ
 * اليوم قد لا تبقى كذلك.
 */
class LocalInvoiceShowTest extends TestCase
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

    /** ما يرسله النموذج للفاتورة المحلية: أصفار في حقول الاستيراد المخفيّة. */
    private function store(array $items): PurchaseInvoice
    {
        $this->post(route('admin.purchasing.invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'currency' => 'ILS',
            'fx_rate_to_usd' => 0,
            'usd_rate' => 0,
            'items' => $items,
        ])->assertSessionHasNoErrors();

        return PurchaseInvoice::latest('id')->firstOrFail();
    }

    private function variantRow(float $cost = 10): array
    {
        return ['variant_id' => ProductVariant::factory()->create()->id, 'qty' => 1, 'unit_cost' => $cost, 'tax_rate' => 0];
    }

    public function test_a_variant_item_opens(): void
    {
        $this->get(route('admin.purchasing.invoices.show', $this->store([$this->variantRow(120)])))->assertOk();
    }

    /** بندٌ بوصفٍ حرّ بلا صنف — كأجور التخليص. */
    public function test_a_description_only_item_opens(): void
    {
        $invoice = $this->store([[
            'description' => 'أجور تخليص', 'qty' => 1, 'unit_cost' => 400, 'tax_rate' => 0,
        ]]);

        $this->get(route('admin.purchasing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('أجور تخليص');
    }

    /** وبندٌ يُنشئ صنفًا جديدًا من الفاتورة. */
    public function test_a_new_product_item_opens(): void
    {
        $invoice = $this->store([[
            'new_name' => 'صنف جديد من الفاتورة', 'sell_price' => 90,
            'qty' => 2, 'unit_cost' => 55, 'tax_rate' => 0,
        ]]);

        $this->get(route('admin.purchasing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('صنف جديد من الفاتورة');
    }

    /** والثلاثة معًا في فاتورةٍ واحدة. */
    public function test_mixed_items_open(): void
    {
        $invoice = $this->store([
            $this->variantRow(),
            ['description' => 'وصف حرّ', 'qty' => 1, 'unit_cost' => 20, 'tax_rate' => 0],
            ['new_name' => 'ثالث', 'qty' => 1, 'unit_cost' => 30, 'tax_rate' => 0],
        ]);

        $this->get(route('admin.purchasing.invoices.show', $invoice))->assertOk();
    }

    /** والمُرحّلة كذلك — لها قيدٌ يُعرض رابطًا. */
    public function test_a_posted_invoice_opens(): void
    {
        $invoice = $this->store([$this->variantRow()]);

        $this->post(route('admin.purchasing.invoices.post', $invoice))->assertRedirect();

        $this->get(route('admin.purchasing.invoices.show', $invoice->fresh()))->assertOk();
    }
}
