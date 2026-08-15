<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المنتج ذو المقاسات/الألوان: أين يدخل رصيده ومن أين يُوزَّع.
 *
 * المتغيّر الافتراضي المجرّد حاملٌ فارغ لا صنفٌ يُباع. البضاعة الداخلة عليه لا
 * تنتمي لأيّ مقاس فلا تُعرض ولا تُحجَز — ومصفوفةُ المقاسات كانت تعجز عن استعادتها
 * لأنها تحسب طرفًا واحدًا من الرصيد.
 */
class VariableProductStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    /** @var array<string, ProductVariant> */
    private array $sizes = [];

    private ProductVariant $placeholder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->firstOrFail();

        // منتج بمقاسين: متغيّر افتراضي مجرّد + متغيّرا خيارات.
        $this->product = Product::factory()->active()->create();
        $this->placeholder = $this->product->defaultVariant;

        $attribute = ProductAttribute::create(['name' => 'المقاس', 'slug' => 'size', 'is_active' => true]);
        foreach (['M', 'L'] as $i => $label) {
            $value = ProductAttributeValue::create([
                'attribute_id' => $attribute->id, 'value' => $label, 'label' => $label,
                'sort_order' => $i, 'is_active' => true,
            ]);
            $variant = ProductVariant::factory()->create([
                'product_id' => $this->product->id, 'is_default' => false, 'is_active' => true,
            ]);
            $variant->attributeValues()->attach($value->id);
            $this->sizes[$label] = $variant->fresh('attributeValues');
        }
    }

    private function stock(ProductVariant $variant): float
    {
        return (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand');
    }

    private function receive(ProductVariant $variant, float $qty): void
    {
        app(InventoryService::class)->receive($variant, $this->warehouse, $qty, 10);
    }

    /** @param  array<string, float>  $quantities */
    private function syncSizes(array $quantities)
    {
        $combos = [];
        foreach ($quantities as $label => $qty) {
            $combos[] = [
                'values' => $this->sizes[$label]->attributeValues->pluck('id')->all(),
                'price' => 150,
                'stock' => $qty,
            ];
        }

        return $this->post(route('admin.products.variants.sync', $this->product), ['combos' => $combos]);
    }

    public function test_the_matrix_counts_the_placeholder_and_the_sizes_together(): void
    {
        // الحالة التي أوقعت المستخدم: مقاسات مُوزَّعة سابقًا (100)، ثم دخلت فاتورةُ
        // شراءٍ بـ50 على المتغيّر المجرَّد. الرصيد الحقيقي 150 لا 50 ولا 100.
        $this->receive($this->sizes['M'], 60);
        $this->receive($this->sizes['L'], 40);
        $this->receive($this->placeholder, 50);

        $this->get(route('admin.products.edit', $this->product))
            ->assertOk()
            ->assertViewHas('variantMatrix', fn (array $m) => abs($m['originalQty'] - 150) < 0.001);
    }

    public function test_redistributing_absorbs_the_placeholder_stock(): void
    {
        $this->receive($this->sizes['M'], 60);
        $this->receive($this->sizes['L'], 40);
        $this->receive($this->placeholder, 50);

        // 150 = 100 القديمة + 50 التي دخلت على المجرَّد.
        $this->syncSizes(['M' => 110, 'L' => 40])->assertRedirect();

        $this->assertEqualsWithDelta(110, $this->stock($this->sizes['M']->fresh()), 0.001);
        $this->assertEqualsWithDelta(40, $this->stock($this->sizes['L']->fresh()), 0.001);
        // والحامل الفارغ يعود صفرًا فلا يُحتسب الرصيد مرّتين.
        $this->assertEqualsWithDelta(0, $this->stock($this->placeholder->fresh()), 0.001);
    }

    public function test_a_total_that_does_not_match_is_still_refused(): void
    {
        // الحارس الأصلي باقٍ: المصفوفة توزيعٌ لا إضافة.
        $this->receive($this->sizes['M'], 60);
        $this->receive($this->placeholder, 40);

        $this->from(route('admin.products.edit', $this->product))
            ->syncSizes(['M' => 200, 'L' => 100])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEqualsWithDelta(60, $this->stock($this->sizes['M']->fresh()), 0.001);
    }

    public function test_distributing_from_a_fresh_placeholder_still_works(): void
    {
        // الحالة الأولى المعتادة: كل الرصيد على المجرَّد ثم يُوزَّع.
        $this->receive($this->placeholder, 90);

        $this->syncSizes(['M' => 50, 'L' => 40])->assertRedirect();

        $this->assertEqualsWithDelta(50, $this->stock($this->sizes['M']->fresh()), 0.001);
        $this->assertEqualsWithDelta(40, $this->stock($this->sizes['L']->fresh()), 0.001);
        $this->assertEqualsWithDelta(0, $this->stock($this->placeholder->fresh()), 0.001);
    }

    public function test_the_purchase_form_hides_the_placeholder_and_names_the_sizes(): void
    {
        $response = $this->get(route('admin.purchasing.invoices.create'))->assertOk();

        // المقاسات تُعرض بأسمائها لا بـSKU يتشابه.
        $response->assertSee('value="'.$this->sizes['M']->id.'"', false);
        $response->assertSee('value="'.$this->sizes['L']->id.'"', false);
        // والحامل الفارغ لا يظهر أصلًا.
        $response->assertDontSee('value="'.$this->placeholder->id.'"', false);
    }

    public function test_a_simple_product_stays_selectable(): void
    {
        // منتج بلا خيارات: متغيّره الافتراضي هو صنفه، فيبقى في القائمة.
        $simple = Product::factory()->active()->create();

        $this->get(route('admin.purchasing.invoices.create'))
            ->assertOk()
            ->assertSee('value="'.$simple->defaultVariant->id.'"', false);
    }

    public function test_buying_the_placeholder_of_a_variable_product_is_refused(): void
    {
        $supplier = Supplier::factory()->create();

        $this->from(route('admin.purchasing.invoices.create'))
            ->post(route('admin.purchasing.invoices.store'), [
                'supplier_id' => $supplier->id,
                'invoice_date' => now()->toDateString(),
                'items' => [['variant_id' => $this->placeholder->id, 'qty' => 100, 'unit_cost' => 35]],
            ])
            ->assertSessionHasErrors('items.0.variant_id');

        $this->assertEqualsWithDelta(0, $this->stock($this->placeholder->fresh()), 0.001);
    }

    public function test_buying_a_specific_size_is_allowed(): void
    {
        $supplier = Supplier::factory()->create();

        $this->post(route('admin.purchasing.invoices.store'), [
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [['variant_id' => $this->sizes['M']->id, 'qty' => 100, 'unit_cost' => 35]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(100, $this->stock($this->sizes['M']->fresh()), 0.001);
    }
}
