<?php

namespace Tests\Feature\Recommendations;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Recommendations\Models\ProductRecommendation;
use App\Modules\Recommendations\Models\RecommendationEvent;
use App\Modules\Recommendations\Services\RecommendationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private RecommendationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->svc = app(RecommendationService::class);
    }

    private function product(Category $category, bool $inStock = true): Product
    {
        $product = Product::factory()->active()->create(['category_id' => $category->id, 'visibility' => 'visible']);
        if ($inStock) {
            app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, 10, 50);
        }

        return $product->fresh();
    }

    public function test_related_returns_same_category_available_products_with_source(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->product($category);
        $peer = $this->product($category);

        $related = $this->svc->related($anchor, 8);

        $this->assertTrue($related->contains('id', $peer->id));
        $this->assertFalse($related->contains('id', $anchor->id)); // لا يوصي بنفسه
        $this->assertSame('catalog', $related->first()->recommendation_source);
        $this->assertNotNull($related->first()->recommendation_reason);
    }

    public function test_never_recommends_out_of_stock_or_inactive(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->product($category);
        $outOfStock = $this->product($category, inStock: false);
        $inactive = Product::factory()->create(['category_id' => $category->id, 'status' => 'draft', 'is_active' => false]);

        $related = $this->svc->related($anchor, 8);

        $this->assertFalse($related->contains('id', $outOfStock->id));
        $this->assertFalse($related->contains('id', $inactive->id));
    }

    public function test_manual_include_and_exclude_are_honored(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->product($category);
        $peer = $this->product($category);
        $pinned = $this->product(Category::factory()->create()); // فئة مختلفة، يُثبَّت يدويًا

        // استثناء الجار الحسابي.
        ProductRecommendation::create([
            'product_id' => $anchor->id, 'recommended_product_id' => $peer->id,
            'type' => 'related', 'kind' => 'exclude',
        ]);
        // تثبيت منتج من فئة أخرى.
        ProductRecommendation::create([
            'product_id' => $anchor->id, 'recommended_product_id' => $pinned->id,
            'type' => 'related', 'kind' => 'include', 'position' => 1,
        ]);

        $related = $this->svc->related($anchor, 8);

        $this->assertFalse($related->contains('id', $peer->id), 'المستثنى يجب ألا يظهر');
        $this->assertTrue($related->contains('id', $pinned->id), 'المثبّت يجب أن يظهر');
        $this->assertSame($pinned->id, $related->first()->id, 'المثبّت يتصدّر');
    }

    public function test_upsell_returns_higher_priced_same_category(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->product($category);
        $anchor->defaultVariant->update(['retail_price' => 100, 'is_default' => true]);
        $cheaper = $this->product($category);
        $cheaper->defaultVariant->update(['retail_price' => 50, 'is_default' => true]);
        $pricier = $this->product($category);
        $pricier->defaultVariant->update(['retail_price' => 150, 'is_default' => true]);

        $upsell = $this->svc->upsell($anchor->fresh(), 8);

        $this->assertTrue($upsell->contains('id', $pricier->id));
        $this->assertFalse($upsell->contains('id', $cheaper->id));
        $this->assertFalse($upsell->contains('id', $anchor->id));
    }

    public function test_tracking_endpoint_records_event(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->product($category);
        $rec = $this->product($category);

        $this->postJson(route('storefront.recommendations.track'), [
            'source_product_id' => $anchor->id,
            'recommended_product_id' => $rec->id,
            'type' => 'related',
            'event' => 'click',
            'placement' => 'product',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('recommendation_events', [
            'recommended_product_id' => $rec->id,
            'event' => 'click',
            'placement' => 'product',
        ]);
        $this->assertSame(1, RecommendationEvent::count());
    }
}
