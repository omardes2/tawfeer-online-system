<?php

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اقتراحات البحث الفوري.
 *
 * كان على الزبون تهجئة اسم المنتج كاملًا ثم الانتقال إلى صفحة النتائج ليعرف
 * إن كان موجودًا. صار كتابة حرفين تكفي لعرض الأسماء المطابقة فيختار منها.
 *
 * الاقتراح تحسينٌ فوق نموذج قائم لا بديلٌ عنه: النموذج نفسه ما زال يُرسل إلى
 * `/search` بلا جافاسكربت، ولذلك تحرس الاختبارات **اتّفاق** الاقتراح مع النتيجة
 * — اقتراحٌ يقود إلى صفحة فارغة أسوأ من غياب الاقتراح.
 */
class SearchSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string, mixed>> */
    private function suggest(string $q): array
    {
        return $this->getJson(route('storefront.search.suggest', ['q' => $q]))
            ->assertOk()
            ->json('data');
    }

    private function product(string $name): Product
    {
        return Product::factory()->active()->create(['name' => $name, 'visibility' => 'visible']);
    }

    public function test_two_letters_bring_back_matching_product_names(): void
    {
        $this->product('شاحن سريع');
        $this->product('مكنسة كهربائية');

        $labels = array_column($this->suggest('شا'), 'label');

        $this->assertContains('شاحن سريع', $labels);
        $this->assertNotContains('مكنسة كهربائية', $labels);
    }

    public function test_a_single_letter_suggests_nothing(): void
    {
        $this->product('شاحن سريع');

        // حرفٌ واحد يطابق نصف الفهرس؛ قائمةٌ بذلك الحجم ليست مساعدة.
        $this->assertSame([], $this->suggest('ش'));
        $this->assertSame([], $this->suggest(''));
    }

    public function test_names_starting_with_the_typed_letters_come_first(): void
    {
        // من كتب «قم» يقصد «قميص» لا «طقم» — البادئة تسبق ما يحتوي الحروف وسطه.
        $this->product('طقم أواني');
        $this->product('قميص قطني');

        $labels = array_column($this->suggest('قم'), 'label');

        $this->assertSame(['قميص قطني', 'طقم أواني'], $labels);
    }

    public function test_hidden_and_inactive_products_never_surface(): void
    {
        Product::factory()->create(['name' => 'شاحن مسودّة', 'visibility' => 'visible']);
        Product::factory()->active()->create(['name' => 'شاحن مخفي', 'visibility' => 'hidden']);

        $this->assertSame([], $this->suggest('شاحن'));
    }

    public function test_categories_and_brands_are_suggested_with_their_own_links(): void
    {
        Category::factory()->create(['name' => 'الجوالات', 'slug' => 'phones', 'is_active' => true]);
        Brand::factory()->create(['name' => 'الجوهرة', 'slug' => 'jawhara', 'is_active' => true]);

        $byType = collect($this->suggest('الجو'))->keyBy('type');

        $this->assertSame(route('storefront.category', 'phones'), $byType['category']['url']);
        $this->assertSame(route('storefront.brand', 'jawhara'), $byType['brand']['url']);
    }

    public function test_a_suggested_product_really_appears_in_the_results_page(): void
    {
        // الاقتراح والنتيجة يستعملان المطابقة نفسها عمدًا؛ هذا يحرس ذلك.
        $this->product('سمّاعة بلوتوث');

        $first = $this->suggest('سمّ')[0];

        $this->assertSame('product', $first['type']);
        $this->get(route('storefront.search', ['q' => 'سمّ']))
            ->assertOk()
            ->assertSee($first['label'], false);
    }

    public function test_suggestion_urls_open_a_real_page(): void
    {
        $this->product('شاحن سريع');

        // رابطٌ يعطي 404 يكسر ثقة الزبون بالقائمة كلّها.
        $this->get($this->suggest('شاح')[0]['url'])->assertOk();
    }
}
