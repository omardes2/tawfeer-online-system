<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فحص تكلفة الأصناف.
 *
 * صنفٌ بتكلفة صفر يظهر ربحُه **كامل سعر بيعه** — فيتصدّر لوحة قرار الصنف
 * ويُضخّم مجمل الربح ويُغري بشراء المزيد منه. والرقم اختراعٌ لا مبالغة.
 */
class CostCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function product(string $name, float $cost, ?float $wholesale, float $retail = 100): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'retail_price' => $retail,
            'wholesale_price' => $wholesale,
            'average_cost' => $cost,
            'cost_price' => $cost,
            'status' => 'active',
            'is_active' => true,
        ]);

        $product->defaultVariant->forceFill([
            'average_cost' => $cost,
            'wholesale_price' => $wholesale,
            'retail_price' => $retail,
        ])->save();

        return $product->fresh();
    }

    private function check(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('catalog:cost-check', $options));

        return Artisan::output();
    }

    /** صنفٌ بتكلفة وسعر جملة لا يُبلَّغ عنه. */
    public function test_a_healthy_product_is_not_reported(): void
    {
        $this->product('مكنسة', cost: 40, wholesale: 60);

        $this->assertStringContainsString('كل صنفٍ له تكلفة وسعر جملة', $this->check());
    }

    /**
     * **وصنفٌ بلا تكلفة يُكشَف — وربحُه يُقال وهميًّا.**
     *
     * هو خللٌ صامت: لا شيء في الشاشات يقول إن الرقم مُخترَع.
     */
    public function test_a_zero_cost_product_is_caught(): void
    {
        $this->product('عطر سمارت', cost: 0, wholesale: 60);

        $output = $this->check();

        $this->assertStringContainsString('عطر سمارت', $output);
        $this->assertStringContainsString('وهمي', $output);
    }

    /** وصنفٌ بلا سعر جملة يُكشَف — يُفسد ربح المسوّق وعمولته. */
    public function test_a_product_without_a_wholesale_price_is_caught(): void
    {
        $this->product('شواية', cost: 40, wholesale: null);

        $output = $this->check();

        $this->assertStringContainsString('شواية', $output);
        $this->assertStringContainsString('ربح المسوّق مُضخَّم', $output);
    }

    /** ومن ينقصه الاثنان يُقال ذلك صراحةً. */
    public function test_missing_both_is_named(): void
    {
        $this->product('صنف ناقص', cost: 0, wholesale: null);

        $this->assertStringContainsString('بلا تكلفة ولا جملة', $this->check());
    }

    /** و`--sold` يحصر الفحص بما بِيع فعلًا. */
    public function test_the_sold_flag_narrows_to_sold_variants(): void
    {
        $sold = $this->product('عطر سمارت', cost: 0, wholesale: 60);
        $this->product('صنف لم يُبَع', cost: 0, wholesale: 60);

        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
            'status' => 'confirmed',
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'variant_id' => $sold->defaultVariant->id,
            'qty' => 1, 'unit_price' => 100, 'discount' => 0, 'line_total' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $output = $this->check(['--sold' => true]);

        $this->assertStringContainsString('عطر سمارت', $output);
        $this->assertStringNotContainsString('صنف لم يُبَع', $output);
    }

    /**
     * **ولا يكتب شيئًا.**
     *
     * فحصٌ يُقرأ ثم يُقرَّر — وتصحيحُ تكلفةٍ قرارٌ لا استنتاج.
     */
    public function test_it_writes_nothing(): void
    {
        $product = $this->product('عطر سمارت', cost: 0, wholesale: 60);

        $this->check();

        $this->assertEqualsWithDelta(0.0, (float) $product->fresh()->average_cost, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $product->defaultVariant->fresh()->average_cost, 0.001);
    }
}
