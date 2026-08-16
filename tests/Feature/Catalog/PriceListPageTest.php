<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Support\AdminNavigation;
use App\Modules\Inventory\Models\InventoryStock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة «الأصناف والأسعار» — قائمة أسعار للمسوّق.
 *
 * غايتها أن يعرف المسوّق ما يبيعه وبكم بلا أن يُفتح له الكتالوج: لا تكلفة ولا
 * مخزون ولا تعديل. ولذلك هي قسمٌ مستقلّ في القائمة الجانبية — قسم «المنتجات»
 * محجوبٌ عنه أصلًا.
 */
class PriceListPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function labels(): array
    {
        return collect(AdminNavigation::groups())
            ->flatMap(fn ($g) => array_column($g['items'], 'label'))->all();
    }

    public function test_admin_manager_and_affiliate_can_open_it(): void
    {
        foreach (['admin', 'manager', 'affiliate'] as $role) {
            $this->actingAs($this->withRole($role))
                ->get(route('admin.price_list'))
                ->assertOk();
        }
    }

    /** ولا أحد غيرهم: موظف المبيعات والمستودع والمحاسب محجوبون. */
    public function test_other_roles_are_forbidden(): void
    {
        foreach (['sales', 'warehouse', 'accountant'] as $role) {
            $this->actingAs($this->withRole($role))
                ->get(route('admin.price_list'))
                ->assertForbidden();
        }
    }

    public function test_the_sidebar_shows_it_to_the_affiliate_and_hides_the_catalog(): void
    {
        $this->actingAs($this->withRole('affiliate'));

        $labels = $this->labels();
        $this->assertContains('الأصناف والأسعار', $labels);
        // القسم المستقلّ هو المقصود: بنود الكتالوج تبقى محجوبة عنه.
        $this->assertNotContains('المنتجات', $labels);
        $this->assertNotContains('الفئات', $labels);
    }

    public function test_it_shows_both_prices_and_hides_cost(): void
    {
        $product = Product::factory()->create(['name' => 'صنف القائمة']);
        $product->defaultVariant->update([
            'retail_price' => 120,
            'wholesale_price' => 80,
            'cost_price' => 47,
            'average_cost' => 47,
        ]);

        $this->actingAs($this->withRole('affiliate'))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('صنف القائمة', false)
            ->assertSee('120.00')
            ->assertSee('80.00')
            ->assertDontSee('47.00'); // التكلفة ليست من شأن المسوّق
    }

    public function test_the_category_filter_narrows_the_list(): void
    {
        $shown = Category::factory()->create(['name' => 'فئة ظاهرة']);
        $hidden = Category::factory()->create(['name' => 'فئة أخرى']);
        Product::factory()->create(['name' => 'صنف الفئة الأولى', 'category_id' => $shown->id]);
        Product::factory()->create(['name' => 'صنف الفئة الثانية', 'category_id' => $hidden->id]);

        $this->actingAs($this->withRole('affiliate'))
            ->get(route('admin.price_list', ['category' => $shown->id]))
            ->assertOk()
            ->assertSee('صنف الفئة الأولى', false)
            ->assertDontSee('صنف الفئة الثانية', false);
    }

    /** المقاسات المختلفة الأسعار تظهر بمدًى لا برقمٍ يُخفي البقية. */
    public function test_differing_variant_prices_show_as_a_range(): void
    {
        $product = Product::factory()->create(['name' => 'صنف بمقاسات']);
        // `retail_price` غير قابل للإفراغ على المتغيّر، فالافتراضي يحمل الطرف الأدنى.
        $product->defaultVariant->update(['retail_price' => 100, 'wholesale_price' => 60]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'PL-RANGE-1',
            'is_default' => false,
            'retail_price' => 140,
            'wholesale_price' => 90,
        ]);

        $this->actingAs($this->withRole('affiliate'))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('100.00 – 140.00', false)
            ->assertSee('60.00 – 90.00', false);
    }

    /** صورة الصنف المصغّرة بجانب اسمه. */
    public function test_it_shows_the_product_thumbnail(): void
    {
        $product = Product::factory()->create(['name' => 'صنف بصورة']);
        $image = $product->images()->create(['path' => 'products/demo.jpg', 'is_primary' => true, 'sort_order' => 0]);

        $this->actingAs($this->withRole('affiliate'))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee($image->url(), false);
    }

    /** المقاسات وتوفّرها — بلا كميات، والنافد يبقى ظاهرًا مشطوبًا لا محذوفًا. */
    public function test_it_lists_variant_options_without_leaking_quantities(): void
    {
        $product = Product::factory()->create(['name' => 'صنف بمقاسات ومخزون']);
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $attribute = ProductAttribute::create(['name' => 'المقاس', 'slug' => 'size-pl', 'type' => 'select']);

        foreach ([['L', 25], ['XL', 0]] as $i => [$label, $onHand]) {
            $value = ProductAttributeValue::create([
                'attribute_id' => $attribute->id, 'value' => $label, 'label' => $label, 'sort_order' => $i,
            ]);
            $variant = ProductVariant::factory()->create([
                'product_id' => $product->id, 'sku' => 'PL-SIZE-'.$label,
                'is_default' => false, 'retail_price' => 100, 'wholesale_price' => 60,
            ]);
            $variant->attributeValues()->attach($value->id);

            if ($onHand > 0) {
                InventoryStock::create([
                    'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
                    'on_hand' => $onHand, 'reserved' => 0,
                ]);
            }
        }

        $html = $this->actingAs($this->withRole('affiliate'))
            ->get(route('admin.price_list'))->assertOk()->getContent();

        // المقاسان ظاهران كلاهما.
        $this->assertStringContainsString('L', $html);
        $this->assertStringContainsString('XL', $html);

        // النافد مشطوب لا محذوف.
        $this->assertStringContainsString('line-through', $html);

        // ولا يتسرّب الرصيد: «7» لا تظهر في خانة المقاسات — أرقام المستودع
        // شأنٌ داخلي، والمسوّق يحتاج «متوفّر أم لا» لا غير.
        preg_match_all('/<td[^>]*>(?:(?!<\/td>).)*line-through(?:(?!<\/td>).)*<\/td>/s', $html, $cells);
        $this->assertNotEmpty($cells[0]);
        $this->assertStringNotContainsString('7', $cells[0][0]);
    }

    /**
     * تنبيه «في نهايته» لما دون العشرة — بلا رقم.
     *
     * المقاس الموشك على النفاد يبدو متوفّرًا تمامًا كالمكدَّس، فيَعِد المسوّق
     * زبونًا بما لا يكفيه. التنبيه كلمةٌ لا عدد: الرصيد يبقى شأنًا داخليًّا.
     */
    public function test_a_variant_below_ten_is_flagged_as_running_out(): void
    {
        $product = Product::factory()->create(['name' => 'صنف على وشك النفاد']);
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $attribute = ProductAttribute::create(['name' => 'المقاس', 'slug' => 'size-low', 'type' => 'select']);

        // 4 قطع = تنبيه، و40 = لا تنبيه.
        foreach ([['M', 4], ['L', 40]] as $i => [$label, $onHand]) {
            $value = ProductAttributeValue::create([
                'attribute_id' => $attribute->id, 'value' => $label, 'label' => $label, 'sort_order' => $i,
            ]);
            $variant = ProductVariant::factory()->create([
                'product_id' => $product->id, 'sku' => 'PL-LOW-'.$label,
                'is_default' => false, 'retail_price' => 100, 'wholesale_price' => 60,
            ]);
            $variant->attributeValues()->attach($value->id);
            InventoryStock::create([
                'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
                'on_hand' => $onHand, 'reserved' => 0,
            ]);
        }

        $html = $this->actingAs($this->withRole('affiliate'))
            ->get(route('admin.price_list'))->assertOk()->getContent();

        // تنبيه واحد لا اثنان: المقاس المكدَّس لا يُوسَم.
        $this->assertSame(1, substr_count($html, __('(في نهايته)')));
        // وبلا عدد: لا «4» ولا «40» في خانة المقاسات.
        preg_match('/<td[^>]*>(?:(?!<\/td>).)*في نهايته(?:(?!<\/td>).)*<\/td>/su', $html, $cell);
        $this->assertNotEmpty($cell);
        $this->assertStringNotContainsString('4', $cell[0]);
    }
}
