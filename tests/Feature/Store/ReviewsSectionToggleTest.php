<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Services\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إظهار قسم التقييمات في المتجر وإخفاؤه بإعدادٍ واحد.
 *
 * الإطفاء لا يحذف رأيًا: يُخفي القسم ويُغلق استقبال الجديد، وتعود التقييمات
 * المحفوظة بالإشعال. وقسمٌ فارغ يقول «لا توجد تقييمات» أسوأ من غيابه.
 */
class ReviewsSectionToggleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->product = Product::factory()->create(['name' => 'جهاز تعطير', 'status' => 'active']);
        $this->product->update(['is_active' => true]);
    }

    private function page(): string
    {
        return $this->get(route('storefront.product', $this->product->slug))->assertOk()->getContent();
    }

    public function test_the_section_shows_by_default(): void
    {
        $this->assertTrue((bool) Settings::get('storefront.reviews_enabled', true));
        $this->assertStringContainsString(__('storefront.reviews_heading'), $this->page());
    }

    public function test_turning_it_off_hides_the_section(): void
    {
        Settings::set('storefront.reviews_enabled', false, 'storefront', 'boolean');

        $this->assertStringNotContainsString(__('storefront.reviews_heading'), $this->page());
    }

    /** والإغلاق في الخادم لا في الواجهة: إخفاء النموذج لا يمنع إرسالًا مباشرًا. */
    public function test_submitting_a_review_is_refused_while_off(): void
    {
        Settings::set('storefront.reviews_enabled', false, 'storefront', 'boolean');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('storefront.product.reviews.store', $this->product->slug), ['rating' => 5])
            ->assertNotFound();
    }

    /** ويعود كما كان بالإشعال — الإعداد عرضٌ لا حذف. */
    public function test_turning_it_back_on_restores_the_section(): void
    {
        Settings::set('storefront.reviews_enabled', false, 'storefront', 'boolean');
        Settings::set('storefront.reviews_enabled', true, 'storefront', 'boolean');

        $this->assertStringContainsString(__('storefront.reviews_heading'), $this->page());
    }
}
