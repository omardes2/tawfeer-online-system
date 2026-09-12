<?php

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «عروض التوفير» — قسمٌ في الرئيسية وصفحةٌ قائمة بذاتها.
 *
 * ## ما يُثبَّت هنا
 *
 * أنّ تعريف «العرض» واحدٌ في الثلاثة: البطاقة التي ترسم شارة الخصم، والقسم الذي
 * يختار العشرة، والصفحة التي تعرض الباقي. لو افترقت، لظهر في «عروض التوفير» صنفٌ
 * بلا شارة خصم — يُقرأ عطلًا لا عرضًا.
 *
 * وأنّ الصفحة تبقى صفحة عروض تحت الاستعمال: الترتيب والفلترة لا يُسقطان العروض
 * عائدَين بالزبون إلى المتجر كاملًا.
 */
class SavingsOffersTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    /**
     * منتجٌ معروض ومتوفّر. `promo` يضع سعرًا ترويجيًّا على المتغيّر الافتراضي —
     * وهو بعينه ما تقرأ منه البطاقة سعرها.
     */
    private function product(string $name, float $price = 100, ?float $promo = null, array $attrs = []): Product
    {
        // `array_merge` لا `+`: اتّحاد المصفوفات يُبقي اليسار عند تكرار المفتاح،
        // فكان `visibility` المُمرَّر يسقط صامتًا ويمرّ اختبار المخفيّ بلا إخفاء.
        $product = Product::factory()->active()->create(array_merge([
            'name' => $name,
            'retail_price' => $price,
            'visibility' => 'visible',
        ], $attrs));

        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price, 'promo_price' => $promo]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 10, $price);

        return $product->refresh();
    }

    // ────────── التعريف ──────────

    /** **ما عليه خصم يظهر، وما لا خصم عليه لا يظهر.** */
    public function test_the_offers_page_lists_only_discounted_products(): void
    {
        $onOffer = $this->product('مكواة بخصم', 100, 80);
        $fullPrice = $this->product('مكواة بسعرها', 100);

        $res = $this->get(route('storefront.offers'))->assertOk();

        $res->assertSee($onOffer->slug);
        $res->assertDontSee($fullPrice->slug);
    }

    /**
     * وسعرٌ ترويجيّ لا يقلّ عن التجزئة **ليس عرضًا**.
     *
     * البطاقة لا ترسم له شارة خصم (النسبة صفر أو سالبة)، فلا يجوز أن يعدّه القسم
     * عرضًا: صنفٌ في «عروض التوفير» بلا خصمٍ مرئيّ يُقرأ خطأً في الصفحة.
     */
    public function test_a_promo_price_that_is_not_lower_is_not_an_offer(): void
    {
        $equal = $this->product('سعر ترويجي مساوٍ', 100, 100);
        $higher = $this->product('سعر ترويجي أعلى', 100, 120);

        $res = $this->get(route('storefront.offers'))->assertOk();

        $res->assertDontSee($equal->slug);
        $res->assertDontSee($higher->slug);
    }

    /** والمخفيّ يبقى مخفيًّا وإن كان عليه خصم. */
    public function test_a_hidden_product_stays_hidden_even_on_offer(): void
    {
        $hidden = $this->product('مخفي بخصم', 100, 60, ['visibility' => 'hidden']);

        $this->get(route('storefront.offers'))->assertOk()->assertDontSee($hidden->slug);
    }

    // ────────── الرئيسية ──────────

    /** **القسم يظهر في الرئيسية** ومنه «عرض الكل» إلى صفحة العروض. */
    public function test_the_home_page_shows_the_section_and_links_to_the_page(): void
    {
        $onOffer = $this->product('سمّاعة بخصم', 200, 150);

        $res = $this->get(route('storefront.home'))->assertOk();

        $res->assertSee(__('storefront.savings_offers'));
        $res->assertSee($onOffer->slug);
        $res->assertSee(route('storefront.offers'));
    }

    /** ويُخفى القسم وحده إن لم يكن على شيءٍ خصم — لا عنوان فوق فراغ. */
    public function test_the_section_hides_itself_when_nothing_is_discounted(): void
    {
        $this->product('بلا خصم', 100);

        $this->get(route('storefront.home'))->assertOk()
            ->assertDontSee(__('storefront.savings_offers_subtitle'));
    }

    /** **والأعمق خصمًا أوّلًا** — القسم يَعِد بالتوفير فيبدأ بأوفره. */
    public function test_the_section_orders_by_deepest_discount(): void
    {
        $shallow = $this->product('خصم بسيط', 100, 90);   // 10%
        $deep = $this->product('خصم عميق', 100, 40);      // 60%

        $items = $this->get(route('storefront.home'))->assertOk()->viewData('onOffer');

        $this->assertSame(
            [$deep->id, $shallow->id],
            $items->pluck('id')->all(),
        );
    }

    /** وبالنسبة لا بفارق المبلغ: خصمٌ صغير على صنفٍ رخيص أعمقُ مما يبدو. */
    public function test_the_order_is_by_percentage_not_by_amount(): void
    {
        // فارق 50 على صنفٍ بألف = 5%، وفارق 40 على صنفٍ بخمسين = 80%.
        $bigAmount = $this->product('فارق كبير بنسبة صغيرة', 1000, 950);
        $bigPercent = $this->product('فارق صغير بنسبة كبيرة', 50, 10);

        $items = $this->get(route('storefront.home'))->assertOk()->viewData('onOffer');

        $this->assertSame(
            [$bigPercent->id, $bigAmount->id],
            $items->pluck('id')->all(),
        );
    }

    // ────────── الصفحة تحت الاستعمال ──────────

    /**
     * **الترتيب لا يُخرج الزبون من العروض.**
     *
     * أدوات الصفحة كانت تشير إلى «المتجر» ثابتةً، فكان أول ترتيبٍ أو فلترٍ يقذف
     * الزبون إلى المتجر كاملًا ويُسقط العروض صامتًا.
     */
    public function test_sorting_within_the_page_stays_on_the_page(): void
    {
        $onOffer = $this->product('بخصم', 100, 70);
        $fullPrice = $this->product('بسعره', 100);

        $res = $this->get(route('storefront.offers', ['sort' => 'price_asc']))->assertOk();

        $res->assertSee($onOffer->slug);
        $res->assertDontSee($fullPrice->slug);
        // نموذج الترتيب وأدوات الفلترة تعود إلى صفحة العروض لا إلى المتجر.
        $res->assertSee('action="'.route('storefront.offers').'"', false);
    }

    /** والفلترة بالقسم تعمل فوق العروض لا بدلًا منها. */
    public function test_filtering_by_category_narrows_within_the_offers(): void
    {
        $category = Category::factory()->create();
        $inCategory = $this->product('بخصم داخل القسم', 100, 70, ['category_id' => $category->id]);
        $outOfCategory = $this->product('بخصم خارج القسم', 100, 70);

        $res = $this->get(route('storefront.offers', ['category' => $category->slug]))->assertOk();

        $res->assertSee($inCategory->slug);
        $res->assertDontSee($outOfCategory->slug);
    }

    /**
     * ولا يُفرَّغ وعد الصفحة بمعاملٍ في الرابط: الفلتر من المسار لا من الاستعلام.
     */
    public function test_the_offer_filter_cannot_be_switched_off_from_the_url(): void
    {
        $fullPrice = $this->product('بسعره', 100);

        $this->get(route('storefront.offers', ['on_offer' => 0]))->assertOk()
            ->assertDontSee($fullPrice->slug);
    }

    /** وصفحةٌ بلا عروض تقول ذلك، لا «لا توجد منتجات». */
    public function test_an_empty_offers_page_says_there_are_no_offers(): void
    {
        $this->product('بسعره', 100);

        $this->get(route('storefront.offers'))->assertOk()
            ->assertSee(__('storefront.no_offers'));
    }
}
