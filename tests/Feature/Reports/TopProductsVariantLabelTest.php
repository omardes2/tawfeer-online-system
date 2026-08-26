<?php

namespace Tests\Feature\Reports;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * قائمة «الأعلى مبيعًا».
 *
 * التجميع بالمتغيّر لا بالصنف — أيّ لونٍ يُباع سؤالٌ حقيقيّ. لكنّ الاسم وحده
 * يجعل صنفًا بلونين صفّين متطابقين في الشاشة **فيُقرآن تكرارًا**، فيُلحَق
 * وصفُ المتغيّر بالاسم ليفترقا.
 */
class TopProductsVariantLabelTest extends TestCase
{
    use RefreshDatabase;

    private ProductAttribute $colour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->colour = ProductAttribute::create(['name' => 'اللون', 'slug' => 'colour', 'is_active' => true]);
    }

    /** متغيّرٌ بلونٍ محدّد لصنفٍ ما. */
    private function variant(Product $product, string $colour): ProductVariant
    {
        $value = ProductAttributeValue::create([
            'attribute_id' => $this->colour->id,
            'value' => $colour,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'retail_price' => 100,
            'wholesale_price' => 60,
            'is_active' => true,
        ]);

        $variant->attributeValues()->attach($value->id);

        return $variant->fresh('attributeValues');
    }

    private function sell(ProductVariant $variant, float $lineTotal): void
    {
        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
            'status' => 'confirmed',
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'qty' => 1,
            'unit_price' => $lineTotal,
            'discount' => 0,
            'line_total' => $lineTotal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rows()
    {
        return app(ReportingService::class)->products(DateRange::resolve('this_year'), 10);
    }

    /**
     * **صنفٌ بلونين يظهر صفّين مميَّزين لا متطابقين.**
     *
     * هو ما يُقرأ تكرارًا حين لا يحمل الصفّ وصف متغيّره.
     */
    public function test_two_variants_of_one_product_are_told_apart(): void
    {
        $product = Product::factory()->create(['name' => 'جهاز تعطير']);

        $this->sell($this->variant($product, 'أبيض'), 16070.75);
        $this->sell($this->variant($product, 'أسود'), 35823.84);

        $names = $this->rows()->pluck('name');

        $this->assertContains('جهاز تعطير — أسود', $names);
        $this->assertContains('جهاز تعطير — أبيض', $names);
        // ولا صفّ باسم الصنف وحده يلتبس بأخيه.
        $this->assertNotContains('جهاز تعطير', $names);
    }

    /** ويبقى الترتيب على الإيراد. */
    public function test_it_stays_ordered_by_revenue(): void
    {
        $product = Product::factory()->create(['name' => 'جهاز تعطير']);

        $this->sell($this->variant($product, 'أبيض'), 100);
        $this->sell($this->variant($product, 'أسود'), 900);

        $this->assertSame('جهاز تعطير — أسود', $this->rows()->first()->name);
    }

    /**
     * وصنفٌ بلا سمات يبقى باسمه وحده.
     *
     * فإلحاق اسمه بنفسه يُطيل السطر بلا فائدة.
     */
    public function test_a_product_without_attributes_keeps_its_bare_name(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة ذكية']);

        $this->sell($product->defaultVariant, 500);

        $this->assertSame('مكنسة ذكية', $this->rows()->first()->name);
    }

    /** والإيراد لا يتأثّر بالتسمية. */
    public function test_the_revenue_is_unchanged(): void
    {
        $product = Product::factory()->create(['name' => 'جهاز تعطير']);

        $this->sell($this->variant($product, 'أسود'), 1234.56);

        $this->assertEqualsWithDelta(1234.56, (float) $this->rows()->first()->revenue, 0.01);
    }
}
