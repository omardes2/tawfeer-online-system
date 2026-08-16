<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عمود سعر الجملة في قائمة المنتجات.
 *
 * يُقرأ من **المتغيّرات** لا من المتغيّر الافتراضي وحده: المنتج ذو المقاسات قد
 * تختلف أسعار جملته بينها، وافتراضيُّه حاملٌ مجرَّد بلا سعر غالبًا — فيظهر
 * العمود فارغًا وهو ليس كذلك.
 */
class ProductWholesalePriceColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    private function listing(): string
    {
        return $this->get(route('admin.products.index'))->assertOk()->getContent();
    }

    public function test_the_column_header_is_present(): void
    {
        $this->get(route('admin.products.index'))->assertOk()->assertSee(__('سعر الجملة'), false);
    }

    public function test_a_single_price_shows_as_one_number(): void
    {
        $product = Product::factory()->create(['name' => 'صنف بسيط']);
        $product->defaultVariant->update(['wholesale_price' => 70]);

        $this->assertStringContainsString('70.00', $this->listing());
    }

    /** مقاسات بأسعار جملة مختلفة: مدًى لا رقمٌ واحد يُخفي البقية. */
    public function test_differing_variant_prices_show_as_a_range(): void
    {
        $product = Product::factory()->create(['name' => 'صنف بمقاسات']);
        $product->defaultVariant->update(['wholesale_price' => null]);
        foreach ([55, 80] as $i => $price) {
            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'sku' => 'V-RANGE-'.$i,
                'is_default' => false,
                'wholesale_price' => $price,
            ]);
        }

        $this->assertStringContainsString('55.00 – 80.00', $this->listing());
    }

    /** لا سعر جملة على أي متغيّر: يُرجَع لسعر المنتج نفسه قبل إظهار الشرطة. */
    public function test_it_falls_back_to_the_product_price(): void
    {
        $product = Product::factory()->create(['name' => 'صنف بسعر المنتج', 'wholesale_price' => 45]);
        $product->variants()->update(['wholesale_price' => null]);

        $this->assertStringContainsString('45.00', $this->listing());
    }

    public function test_the_row_stays_intact_when_no_price_exists_anywhere(): void
    {
        $product = Product::factory()->create(['name' => 'صنف بلا جملة', 'wholesale_price' => null]);
        $product->variants()->update(['wholesale_price' => null]);

        // الصفحة تُعرض والصنف ظاهر — الخانة وحدها فارغة.
        $this->get(route('admin.products.index'))->assertOk()->assertSee('صنف بلا جملة', false);
    }
}
