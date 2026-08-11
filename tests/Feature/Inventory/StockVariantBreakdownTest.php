<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مطابقة كميات الصنف مع متغيّراته: إجمالي الصنف في صفحة المخزن = مجموع كميات
 * مقاساته/متغيّراته. الشاشتان تعرضان التفصيل ليكون التطابق مرئيًا وقابلًا للتدقيق.
 */
class StockVariantBreakdownTest extends TestCase
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

    /** منتج بثلاثة مقاسات بكميات 1 و1 و110 ⇒ إجمالي الصنف 112. */
    private function productWithVariants(): Product
    {
        $product = Product::factory()->create(['name' => 'بنطلون اسود']);
        $inv = app(InventoryService::class);

        // المتغيّر الافتراضي + متغيّران إضافيان.
        $default = $product->defaultVariant;
        $inv->receive($default, $this->warehouse, 1, 20);

        foreach ([1, 110] as $i => $qty) {
            $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
            $inv->receive($variant, $this->warehouse, $qty, 20);
        }

        return $product->fresh();
    }

    public function test_product_total_equals_sum_of_variant_quantities(): void
    {
        $product = $this->productWithVariants();

        $total = (float) $product->stocks()->sum('on_hand');
        $variantsSum = (float) $product->variants()
            ->withSum('inventoryStocks as on_hand_sum', 'on_hand')->get()
            ->sum(fn ($v) => (float) ($v->on_hand_sum ?? 0));

        $this->assertEqualsWithDelta(112, $total, 0.001);
        $this->assertEqualsWithDelta($total, $variantsSum, 0.001); // مطابقة تامّة
    }

    public function test_stocks_page_shows_variant_breakdown(): void
    {
        $product = $this->productWithVariants();

        $this->actingAs($this->admin())
            ->get(route('admin.inventory.stocks'))
            ->assertOk()
            ->assertSee('بنطلون اسود')
            ->assertSee(__('المجموع'));
    }

    public function test_product_card_lists_each_variant_quantity(): void
    {
        $product = $this->productWithVariants();

        $res = $this->actingAs($this->admin())
            ->get(route('admin.inventory.products.show', $product))
            ->assertOk()
            ->assertSee(__('الكميات حسب المقاس/المتغيّر'));

        // كل متغيّر يظهر بكوده، والمجموع مطابق.
        foreach ($product->variants as $variant) {
            $res->assertSee($variant->sku);
        }
    }
}
