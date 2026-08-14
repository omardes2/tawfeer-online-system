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

    public function test_the_logo_shows_on_mobile_only_and_stays_in_the_drawer(): void
    {
        [$toolbar, $drawer] = $this->headerParts();

        $mark = 'aria-label="'.__('storefront.site_name').'"';
        $this->assertStringContainsString($mark, $toolbar, 'الشعار غادر شريط الترويسة.');
        // على الحواسيب لا شعار: البحث يملأ مكانه والقائمة الأفقية تحمل «الرئيسية».
        $this->assertMatchesRegularExpression(
            '/md:hidden[^>]*>\s*<a[^>]*'.preg_quote($mark, '/').'/s',
            $toolbar,
            'الشعار يظهر على الحواسيب أيضًا — يُفترض أن يكون جوّالًا فقط.'
        );
        // حذفه من الدرج يترك القائمةَ بلا رابط إلى الرئيسية.
        $this->assertStringContainsString($mark, $drawer, 'الشعار غادر درج القائمة.');
    }

    public function test_the_cart_leaves_the_mobile_top_bar_but_stays_reachable(): void
    {
        [$toolbar] = $this->headerParts();

        $at = strpos($toolbar, 'aria-label="'.__('storefront.cart').'"');
        $this->assertNotFalse($at, 'رابط السلة اختفى من الترويسة تمامًا.');

        // `hidden md:grid` يخفيه على الجوّال ويعيده على الحواسيب.
        $anchor = substr($toolbar, strrpos(substr($toolbar, 0, $at), '<a '));
        $this->assertStringContainsString('hidden md:grid', substr($anchor, 0, strpos($anchor, '>')));

        // الإخفاء مقبول فقط لأن الشريط السفلي يحمل السلة بشارتها.
        $this->get(route('storefront.home'))->assertOk()->assertSee('sf-bottomnav', false);
    }

    /**
     * الترويسة مقسومة: شريط علوي ثم درج القائمة (`<aside>` داخل الوسم نفسه).
     *
     * @return array{0: string, 1: string}
     */
    private function headerParts(): array
    {
        $html = $this->get(route('storefront.home'))->assertOk()->getContent();
        $start = strpos($html, '<header');
        $header = substr($html, $start, strpos($html, '</header>') - $start);

        $split = strpos($header, '<aside');
        $this->assertNotFalse($split, 'درج القائمة غير موجود في الترويسة.');

        return [substr($header, 0, $split), substr($header, $split)];
    }

    public function test_inner_pages_still_offer_a_way_home(): void
    {
        // مسار التنقّل يحمل رابط «الرئيسية» — مخرج إضافي لا يعتمد على الشريط السفلي.
        $this->get(route('storefront.shop'))
            ->assertOk()
            ->assertSee(route('storefront.home'), false);
    }
}
