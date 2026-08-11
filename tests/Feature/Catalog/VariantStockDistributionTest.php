<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مصفوفة المتغيّرات **توزّع** كمية الصنف ولا تضيف إليها: مجموع المقاسات يجب أن يساوي
 * العدد الأصلي، وكمية المتغيّر الافتراضي تُصفَّر بعد التوزيع (وإلا احتُسبت مرّتين).
 */
class VariantStockDistributionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('admin');

        return $u;
    }

    /** @return array{0: Product, 1: int, 2: int} المنتج ومعرّفا قيمتَي المقاس S و M */
    private function productWithStock(float $qty = 100): array
    {
        $product = Product::factory()->active()->create(['name' => 'بلوزة']);
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, $qty, 10);

        $attribute = ProductAttribute::create(['name' => 'مقاسات', 'slug' => 'sizes-'.uniqid(), 'is_active' => true]);
        $s = ProductAttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'S', 'is_active' => true]);
        $m = ProductAttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'M', 'is_active' => true]);

        return [$product->fresh(), (int) $s->id, (int) $m->id];
    }

    private function totalStock(Product $product): float
    {
        return (float) InventoryStock::whereIn('variant_id', $product->variants()->pluck('id'))
            ->where('warehouse_id', $this->warehouse->id)->sum('on_hand');
    }

    /** التوزيع الصحيح: 60 + 40 = 100 ⇒ الإجمالي يبقى 100 لا 200. */
    public function test_variant_quantities_distribute_original_without_adding(): void
    {
        [$product, $s, $m] = $this->productWithStock(100);

        $this->actingAs($this->admin())
            ->post(route('admin.products.variants.sync', $product), [
                'combos' => [
                    ['values' => [$s], 'price' => 50, 'stock' => 60],
                    ['values' => [$m], 'price' => 50, 'stock' => 40],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(100, $this->totalStock($product->fresh()), 0.001);

        // المتغيّر الافتراضي صُفِّر (كميته وُزّعت).
        $default = $product->fresh()->variants()->whereDoesntHave('attributeValues')->first();
        $defaultQty = (float) InventoryStock::where('variant_id', $default->id)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand');
        $this->assertEqualsWithDelta(0, $defaultQty, 0.001);
    }

    /** الزيادة مرفوضة: 60 + 60 = 120 ≠ 100. */
    public function test_sum_greater_than_original_is_rejected(): void
    {
        [$product, $s, $m] = $this->productWithStock(100);

        $this->actingAs($this->admin())
            ->post(route('admin.products.variants.sync', $product), [
                'combos' => [
                    ['values' => [$s], 'price' => 50, 'stock' => 60],
                    ['values' => [$m], 'price' => 50, 'stock' => 60],
                ],
            ])->assertRedirect()->assertSessionHas('error');

        // لم يتغيّر شيء: الإجمالي ما زال 100 ولا متغيّرات مقاسات.
        $this->assertEqualsWithDelta(100, $this->totalStock($product->fresh()), 0.001);
    }

    /** النقصان مرفوض أيضًا: 30 + 40 = 70 ≠ 100. */
    public function test_sum_less_than_original_is_rejected(): void
    {
        [$product, $s, $m] = $this->productWithStock(100);

        $this->actingAs($this->admin())
            ->post(route('admin.products.variants.sync', $product), [
                'combos' => [
                    ['values' => [$s], 'price' => 50, 'stock' => 30],
                    ['values' => [$m], 'price' => 50, 'stock' => 40],
                ],
            ])->assertRedirect()->assertSessionHas('error');

        $this->assertEqualsWithDelta(100, $this->totalStock($product->fresh()), 0.001);
    }

    /** إعادة التوزيع بعد التوزيع الأول: 70 + 30 = 100 ⇒ الإجمالي ثابت. */
    public function test_redistribution_keeps_total_constant(): void
    {
        [$product, $s, $m] = $this->productWithStock(100);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.products.variants.sync', $product), [
            'combos' => [
                ['values' => [$s], 'price' => 50, 'stock' => 60],
                ['values' => [$m], 'price' => 50, 'stock' => 40],
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.products.variants.sync', $product->fresh()), [
            'combos' => [
                ['values' => [$s], 'price' => 50, 'stock' => 70],
                ['values' => [$m], 'price' => 50, 'stock' => 30],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(100, $this->totalStock($product->fresh()), 0.001);
    }

    /**
     * صفحة «إنشاء أوردر» يجب أن تعرض كل مقاس بكميته الفعلية — لا بطاقة واحدة على
     * المتغيّر الافتراضي (كميته تصبح صفرًا بعد التوزيع فيبدو الصنف نافدًا خطأً).
     */
    public function test_order_create_page_lists_each_variant_with_its_quantity(): void
    {
        [$product, $s, $m] = $this->productWithStock(100);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.products.variants.sync', $product), [
            'combos' => [
                ['values' => [$s], 'price' => 50, 'stock' => 60],
                ['values' => [$m], 'price' => 50, 'stock' => 40],
            ],
        ])->assertSessionHasNoErrors();

        $res = $this->actingAs($admin)->get(route('admin.sales.orders.create'))->assertOk();
        $products = collect($res->viewData('products'));

        // بطاقتان (مقاسان) بكميتيهما، ولا بطاقة للمتغيّر الافتراضي بكمية صفر.
        $cards = $products->filter(fn ($c) => str_contains($c['name'], 'بلوزة'))->values();
        $this->assertCount(2, $cards);
        $this->assertEqualsCanonicalizing([60.0, 40.0], $cards->pluck('available')->map(fn ($v) => (float) $v)->all());
        foreach ($cards as $card) {
            $this->assertStringContainsString('—', $card['name']); // الاسم يحمل المقاس
        }
    }

    /** المنتج بلا مقاسات يبقى ببطاقة واحدة (سلوك غير متغيّر). */
    public function test_product_without_variants_still_shows_single_card(): void
    {
        $product = Product::factory()->active()->create(['name' => 'معطر']);
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, 10, 5);

        $res = $this->actingAs($this->admin())->get(route('admin.sales.orders.create'))->assertOk();
        $cards = collect($res->viewData('products'))->filter(fn ($c) => $c['name'] === 'معطر')->values();

        $this->assertCount(1, $cards);
        $this->assertEqualsWithDelta(10, (float) $cards[0]['available'], 0.001);
    }
}
