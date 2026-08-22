<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سعر جملةٍ لكل مقاس — حين تختلف الأسعار فعلًا.
 *
 * المقاس الأكبر قد يكلّف أكثر، وإجبارُ كل المقاسات على سعر جملةٍ واحد يُخفي
 * ذلك في الربح والعمولة.
 *
 * والقاعدة الحاكمة: **الفراغ وراثة لا صفر**. الصفر يُقرأ في النظام «لا قيد»،
 * فيسقط معه حارس البيع بأقلّ من الجملة ويهبط أساس عمولة المسوّق إلى التكلفة.
 */
class VariantWholesalePerSizeTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    /** @var array<int, ProductAttributeValue> */
    private array $sizes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->product = Product::factory()->create([
            'name' => 'قميص', 'retail_price' => 100, 'wholesale_price' => 60,
        ]);
        $this->product->defaultVariant->update(['retail_price' => 100, 'wholesale_price' => 60]);

        $attribute = ProductAttribute::create(['name' => 'المقاس', 'slug' => 'size', 'is_active' => true]);

        foreach (['S', 'XL'] as $i => $label) {
            $this->sizes[$label] = ProductAttributeValue::create([
                'attribute_id' => $attribute->id, 'value' => $label, 'label' => $label,
                'sort_order' => $i, 'is_active' => true,
            ]);
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function sync(array $combos): void
    {
        $this->post(route('admin.products.variants.sync', $this->product), ['combos' => $combos])
            ->assertRedirect();
    }

    private function variantFor(string $size): ProductVariant
    {
        return $this->product->variants()
            ->whereHas('attributeValues', fn ($q) => $q->where('product_attribute_values.id', $this->sizes[$size]->id))
            ->firstOrFail();
    }

    /** سعرٌ صريح للمقاس يُحفظ كما أُدخل. */
    public function test_an_explicit_wholesale_price_is_saved_per_size(): void
    {
        $this->sync([
            ['values' => [$this->sizes['S']->id], 'price' => 100, 'wholesale' => 55, 'stock' => 0],
            ['values' => [$this->sizes['XL']->id], 'price' => 120, 'wholesale' => 70, 'stock' => 0],
        ]);

        $this->assertSame('55.00', $this->variantFor('S')->wholesale_price);
        $this->assertSame('70.00', $this->variantFor('XL')->wholesale_price);
    }

    /**
     * والفراغ يعني «كسعر الصنف» لا صفرًا.
     *
     * لو حُفظ صفرًا لسقط حارس البيع بأقلّ من الجملة عن هذا المقاس بالذات،
     * ولهبط أساس عمولة المسوّق إلى التكلفة.
     */
    public function test_a_blank_wholesale_takes_the_product_price_not_zero(): void
    {
        $this->sync([
            ['values' => [$this->sizes['S']->id], 'price' => 100, 'wholesale' => '', 'stock' => 0],
        ]);

        $variant = $this->variantFor('S');

        $this->assertSame('60.00', $variant->wholesale_price);
        $this->assertSame(60.0, $variant->load('product')->effectiveWholesalePrice());
    }

    /** وتفريغه يعيد المقاس إلى سعر الصنف. */
    public function test_clearing_it_returns_the_size_to_the_product_price(): void
    {
        $this->sync([['values' => [$this->sizes['S']->id], 'price' => 100, 'wholesale' => 55, 'stock' => 0]]);
        $this->assertSame('55.00', $this->variantFor('S')->wholesale_price);

        $this->sync([['values' => [$this->sizes['S']->id], 'price' => 100, 'wholesale' => '', 'stock' => 0]]);

        $this->assertSame('60.00', $this->variantFor('S')->wholesale_price);
    }

    /**
     * ولا يُترك العمود فارغًا أبدًا.
     *
     * يُملأ عمدًا ليستقيم مع من يقرؤه باستعلامٍ خام — تقريرٍ أو تصدير — ممّن
     * لا يمرّ بالاحتياط في الكود.
     */
    public function test_the_column_is_never_left_null(): void
    {
        $this->sync([['values' => [$this->sizes['S']->id], 'price' => 100, 'wholesale' => '', 'stock' => 0]]);

        $this->assertNotNull($this->variantFor('S')->getRawOriginal('wholesale_price'));
    }

    /** والحارس يقيس كل مقاسٍ بسعره هو. */
    public function test_the_wholesale_floor_uses_each_size_own_price(): void
    {
        $this->sync([
            ['values' => [$this->sizes['S']->id], 'price' => 100, 'wholesale' => 55, 'stock' => 0],
            ['values' => [$this->sizes['XL']->id], 'price' => 120, 'wholesale' => 90, 'stock' => 0],
        ]);

        $this->assertSame(55.0, $this->variantFor('S')->load('product')->effectiveWholesalePrice());
        $this->assertSame(90.0, $this->variantFor('XL')->load('product')->effectiveWholesalePrice());
    }

    /** والشاشة تعرض العمود. */
    public function test_the_matrix_shows_a_wholesale_column(): void
    {
        $this->get(route('admin.products.edit', $this->product))
            ->assertOk()
            ->assertSee('combos[${i}][wholesale]', false)
            ->assertSee('الجملة', false);
    }

    /** وتُمرَّر القيمة المحفوظة إلى الشاشة فتُقرأ عند التعديل. */
    public function test_the_saved_value_reaches_the_screen(): void
    {
        $this->sync([['values' => [$this->sizes['S']->id], 'price' => 100, 'wholesale' => 55, 'stock' => 0]]);

        $this->get(route('admin.products.edit', $this->product))
            ->assertOk()
            ->assertSee('wholesale', false)
            ->assertSee('55', false);
    }
}
