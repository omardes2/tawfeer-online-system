<?php

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ظهور الترويسة (القائمة/البحث/السلة) حسب الصفحة والشاشة.
 *
 * على **الجوّال** تظهر في الصفحة الرئيسية وحدها؛ في الصفحات الداخلية يتكفّل
 * الشريط السفلي بالتنقّل. وعلى **الحواسيب** تبقى في كل صفحة — لا شريط سفلي
 * هناك، فإخفاؤها يترك الصفحة بلا أي وسيلة تنقّل.
 *
 * الإخفاء بـCSS لا بحذف من الخادم: عرض الشاشة لا يُعرَف وقت التصيير.
 */
class HeaderVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** صنف الترويسة كما يخرج في الصفحة. */
    private function headerClass(string $url): string
    {
        $html = $this->get($url)->assertOk()->getContent();
        $at = strpos($html, '<header');
        $this->assertNotFalse($at, 'الترويسة غير موجودة في الصفحة إطلاقًا.');

        return substr($html, $at, strpos($html, '>', $at) - $at);
    }

    public function test_home_shows_the_header_on_every_screen(): void
    {
        $this->assertStringNotContainsString('hidden md:block', $this->headerClass(route('storefront.home')));
    }

    public function test_inner_pages_hide_the_header_on_mobile_only(): void
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible']);

        $inner = [
            route('storefront.shop'),
            route('storefront.categories'),
            route('storefront.brands'),
            route('storefront.cart'),
            route('storefront.product', $product->slug),
        ];

        foreach ($inner as $url) {
            // `hidden` يخفيها على الجوّال، و`md:block` يعيدها على الحواسيب.
            $this->assertStringContainsString('hidden md:block', $this->headerClass($url), $url);
        }
    }

    public function test_bottom_nav_remains_on_inner_pages(): void
    {
        // شبكة الأمان: إخفاء الترويسة مقبول فقط لأن التنقّل السفلي حاضر.
        $this->get(route('storefront.shop'))
            ->assertOk()
            ->assertSee('sf-bottomnav', false);
    }

    public function test_the_header_holds_one_search_box_in_the_logos_place(): void
    {
        $html = $this->get(route('storefront.home'))->assertOk()->getContent();
        $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));

        // كان نموذجان — واحد للحاسوب وآخر للجوّال في سطر ثانٍ. صارا واحدًا،
        // فمعرّفان متطابقان أو حقلان معًا يعنيان عودة الازدواج.
        $this->assertSame(1, substr_count($header, 'type="search"'));
        $this->assertSame(1, substr_count($header, 'id="sf-search"'));
        $this->assertStringNotContainsString('sf-search-m', $header);
    }

    public function test_the_logo_left_the_header_but_not_the_drawer(): void
    {
        $html = $this->get(route('storefront.home'))->assertOk()->getContent();
        $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));

        // درج القائمة يقع داخل وسم الترويسة، فيُفصل قبل العدّ.
        $toolbar = substr($header, 0, strpos($header, '<aside') ?: strlen($header));
        $drawer = substr($header, strpos($header, '<aside') ?: 0);

        $mark = 'aria-label="'.__('storefront.site_name').'"';
        $this->assertStringNotContainsString($mark, $toolbar, 'الشعار ما زال في شريط الترويسة.');
        // حذفه من الدرج أيضًا يترك القائمةَ بلا رابط إلى الرئيسية.
        $this->assertStringContainsString($mark, $drawer, 'الشعار غادر درج القائمة أيضًا.');
    }

    public function test_inner_pages_still_offer_a_way_home(): void
    {
        // مسار التنقّل يحمل رابط «الرئيسية» — مخرج إضافي لا يعتمد على الشريط السفلي.
        $this->get(route('storefront.shop'))
            ->assertOk()
            ->assertSee(route('storefront.home'), false);
    }
}
