<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمة أصناف فاتورة الشراء: لا خيار بلا اسم.
 *
 * ## العطب
 *
 * حذف المنتج حذفٌ ناعم **له وحده** — والمتغيّرات تبقى حيّة:
 *
 *     public function delete(Product $product): void { $product->delete(); }
 *
 * وقائمةُ الشراء كانت تجلب كل المتغيّرات بلا فحص أن منتجها حيّ، فيعود
 * `$v->product` قيمتُه `null` (نطاق الحذف الناعم يُخفي المنتج) ويُرسَم الخيار:
 *
 *     <option value="{{ $v->id }}">{{ $v->product?->name }}</option>
 *
 * **سطرٌ أبيض يحمل `value` صحيحًا.** يُنقر عليه سهوًا — وهو سهل، فالفراغ لا
 * يُقرأ — فتدخل بضاعة الفاتورة إلى متغيّر منتجٍ محذوف: رصيدٌ حقيقي في المستودع
 * لا يظهر في شاشة الأصناف ولا يُباع ولا يُحجَز، وتكلفتُه تدخل حساب المخزون بلا
 * ما يقابله.
 *
 * وهو من عائلة عطب مولّد الـslug نفسها: محذوفٌ ناعمًا يتسرّب من فحصٍ لا يراه.
 */
class PurchasableVariantListTest extends TestCase
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

    /** @return array<int, array{id: string, label: string}> */
    private function listedOptions(?string $url = null): array
    {
        return $this->get($url ?? route('admin.purchasing.invoices.create'))
            ->assertOk()->viewData('variantOptions');
    }

    /** **متغيّر منتجٍ محذوف لا يظهر في قائمة الشراء.** */
    public function test_a_deleted_products_variant_is_not_offered(): void
    {
        $live = ProductVariant::factory()->create();
        $doomed = ProductVariant::factory()->create();
        $doomed->product->delete();

        $ids = array_column($this->listedOptions(), 'id');

        $this->assertContains((string) $live->id, $ids);
        $this->assertNotContains((string) $doomed->id, $ids);
    }

    /** **ولا خيار بلا اسم** — وهو جوهر العطب: الفراغ يُنقر سهوًا. */
    public function test_no_option_is_nameless(): void
    {
        ProductVariant::factory()->count(3)->create();
        ProductVariant::factory()->create()->product->delete();

        foreach ($this->listedOptions() as $option) {
            $this->assertNotSame('', trim($option['label']));
        }
    }

    /**
     * لكنّ المحذوف الذي **تشير إليه فاتورةٌ مفتوحة** يبقى — وباسمٍ موسوم.
     *
     * إخفاؤه يُفقد السطرَ اختيارَه فيبدو «صنفًا حرًّا» ويُمحى ارتباطه عند الحفظ.
     */
    public function test_a_referenced_deleted_variant_stays_and_is_labelled(): void
    {
        $variant = ProductVariant::factory()->create();

        $invoice = app(PurchaseInvoiceService::class)->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $variant->id, 'qty' => 5, 'unit_cost' => 10]],
        );

        $variant->product->delete();

        $options = collect($this->listedOptions(route('admin.purchasing.invoices.edit', $invoice)))
            ->keyBy('id');

        $this->assertTrue($options->has((string) $variant->id));
        $this->assertStringContainsString('منتج محذوف', $options[(string) $variant->id]['label']);
    }

    /** والمقاس يُسمّى بمقاسه لا بالـSKU وحده. */
    public function test_a_sized_variant_carries_its_option_in_the_label(): void
    {
        $product = Product::factory()->create(['name' => 'مشد كولومبي']);

        $options = collect($this->listedOptions())->firstWhere(
            'id', (string) $product->defaultVariant->id,
        );

        $this->assertNotNull($options);
        $this->assertStringContainsString('مشد كولومبي', $options['label']);
    }

    /** والصفحة تُرسم — القائمة تُمرَّر للواجهة لا تُبنى في القالب. */
    public function test_the_create_page_renders_with_the_search_list(): void
    {
        ProductVariant::factory()->create();

        $this->get(route('admin.purchasing.invoices.create'))
            ->assertOk()
            ->assertSee(__('ابحث عن صنف…'), false);
    }
}
