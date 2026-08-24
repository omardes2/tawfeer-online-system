<?php

namespace Tests\Feature\AiAgent;

use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\AiAgent\Services\SalesAgentPrompt;
use App\Modules\AiAgent\Tools\GetProductDetailsTool;
use App\Modules\AiAgent\Tools\SearchProductsTool;
use App\Modules\Catalog\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوكيل يبيع كلّ الأصناف — لا المجهَّزة وحدها.
 *
 * البوّابة السابقة (`is_ready` شرطًا للظهور في البحث) كانت تحمي من الارتجال،
 * لكنها في متجرٍ فيه ١٥٢ صنفًا وواحدٌ مجهَّز تعني تحويل كل سؤالٍ تقريبًا —
 * فيبدو الوكيل معطوبًا وهو يعمل كما صُمِّم.
 *
 * والحماية انتقلت من **الحجب** إلى **مصدر الكلام**: الكتالوج والأدوات، ومنعٌ
 * صريح من نسبة ميزةٍ لا تَرِد في الوصف.
 */
class AgentSellsAllProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'name' => $name, 'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
    }

    private function search(string $query): array
    {
        return app(SearchProductsTool::class)->handle(['query' => $query])['products'];
    }

    private function details(Product $product): array
    {
        return app(GetProductDetailsTool::class)->handle(['product_id' => $product->id]);
    }

    /** صنفٌ بلا معرفةٍ بيعية يظهر في البحث الآن. */
    public function test_a_product_without_knowledge_is_searchable(): void
    {
        $product = $this->product('حزام الساونا');

        $found = $this->search('ساونا');

        $this->assertCount(1, $found);
        $this->assertSame($product->id, $found[0]['product_id']);
    }

    /** ويُعلَّم بأنه بلا نقاط بيعٍ مكتوبة. */
    public function test_the_search_flags_which_products_have_sales_notes(): void
    {
        $bare = $this->product('حزام الساونا');
        $prepared = $this->product('بدلة الساونا');

        ProductKnowledge::create([
            'product_id' => $prepared->id, 'selling_points' => ['بتشدّ البطن'], 'is_ready' => true,
        ]);

        $flags = collect($this->search('ساونا'))->pluck('has_sales_notes', 'product_id');

        $this->assertFalse($flags[$bare->id]);
        $this->assertTrue($flags[$prepared->id]);
    }

    /** والصنف غير المرئيّ أو المعطَّل يبقى محجوبًا — الفتح للجاهزية لا للحالة. */
    public function test_hidden_and_inactive_products_stay_out(): void
    {
        $this->product('مخفيّ', ['visibility' => 'hidden']);
        $this->product('معطّل', ['is_active' => false]);

        $this->assertSame([], $this->search('مخفيّ'));
        $this->assertSame([], $this->search('معطّل'));
    }

    // ────────── التفاصيل ──────────

    /**
     * وتفاصيله تُرجع الوصف بدل أمرٍ بالتحويل.
     *
     * كان الردّ السابق «حوّل المحادثة إلى موظفة» — أي أن الأداة تُنفق نداءً
     * لتقول «لا أعرف».
     */
    public function test_details_of_a_bare_product_return_its_description(): void
    {
        $product = $this->product('حزام الساونا', [
            'short_description' => 'حزام حراري للخصر',
            'description' => '<p>مقاس واحد يناسب الجميع</p>',
        ]);

        $details = $this->details($product);

        $this->assertFalse($details['is_ready']);
        $this->assertStringContainsString('حزام حراري للخصر', $details['description']);
        // الوسوم تُزال: `<p>` تصل إلى واتساب حرفيًّا إن نسخها النموذج.
        $this->assertStringNotContainsString('<p>', $details['description']);
        $this->assertStringContainsString('مقاس واحد', $details['description']);
    }

    /** ومعه حدُّ صلاحيةٍ صريح بدل الصمت. */
    public function test_a_bare_product_carries_an_explicit_limit(): void
    {
        $details = $this->details($this->product('حزام الساونا'));

        $this->assertStringContainsString('لا تنسب', $details['note']);
    }

    /** والصنف المجهَّز يُرجع معرفته كاملةً — ومعها الوصف. */
    public function test_a_prepared_product_still_returns_its_knowledge(): void
    {
        $product = $this->product('بدلة تخفيف الوزن', ['short_description' => 'بدلة حرارية']);

        ProductKnowledge::create([
            'product_id' => $product->id,
            'selling_points' => ['بتوفّر أجرة عاملة'],
            'objections' => [['objection' => 'غالية', 'response' => 'بتيجي بسعر جلستين']],
            'is_ready' => true,
        ]);

        $details = $this->details($product);

        $this->assertTrue($details['is_ready']);
        $this->assertSame(['بتوفّر أجرة عاملة'], $details['selling_points']);
        $this->assertSame('غالية', $details['objections'][0]['objection']);
        $this->assertStringContainsString('بدلة حرارية', $details['description']);
        $this->assertArrayNotHasKey('note', $details);
    }

    /** وصنفٌ بلا وصفٍ أصلًا يقول ذلك بدل أن يُرجع فراغًا يملؤه النموذج. */
    public function test_a_product_with_no_description_says_so(): void
    {
        $details = $this->details($this->product('صنف بلا وصف', [
            'short_description' => null, 'description' => null,
        ]));

        $this->assertStringContainsString('لا يوجد وصف', $details['description']);
    }

    /** والتعليمات تمنع نسبة ما ليس في الوصف. */
    public function test_the_prompt_forbids_inventing_features(): void
    {
        $prompt = app(SalesAgentPrompt::class)->system();

        $this->assertStringContainsString('لا تنسب للصنف ميزة غير مذكورة', $prompt);
    }
}
