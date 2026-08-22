<?php

namespace Tests\Feature\AiAgent;

use App\Models\User;
use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\AiAgent\Tools\SearchProductsTool;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Support\AdminNavigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشة المعرفة البيعية — ما يقوله الوكيل عن كل صنف.
 *
 * و`is_ready` **إذنٌ بالبيع لا علامةُ اكتمال**: بها يخرج الصنف في بحث الوكيل
 * ويتكلّم عنه، وبدونها يُحوَّل السؤال عنه إلى موظفة. فالفحص الحاسم هنا ليس
 * «هل حُفظ الحقل؟» بل **«هل تغيّر ما يبيعه الوكيل فعلًا؟»**
 */
class ProductKnowledgeScreenTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // نشِطٌ ومرئيّ: أداة البحث تشترطهما مع الجاهزية، والفحص هنا على أثر
        // الجاهزية وحدها.
        $this->product = Product::factory()->create([
            'name' => 'مكنسة بخارية', 'retail_price' => 300,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'selling_points' => ['بتوفّر أجرة عاملة بشهر', 'بتنظّف بلا كيماويات'],
            'use_cases' => ['تنظيف السجاد'],
            'objections' => [['objection' => 'غالية', 'response' => 'بتوفّر أكثر من ثمنها بشهرين']],
            'faq' => [['question' => 'بتشتغل عالكهربا؟', 'answer' => 'إي، 220 فولت']],
            'tone_notes' => 'لا تَعِد بنتيجة فورية على البقع القديمة.',
            'is_ready' => 1,
        ], $overrides);
    }

    /** الشاشتان تفتحان. */
    public function test_the_screens_open(): void
    {
        $this->actingAs($this->admin())->get(route('admin.ai_agent.knowledge.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('admin.ai_agent.knowledge.edit', $this->product))->assertOk();
    }

    /** والحفظ يُنشئ المعرفة كاملةً. */
    public function test_saving_creates_the_knowledge(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload())
            ->assertRedirect();

        $k = ProductKnowledge::where('product_id', $this->product->id)->firstOrFail();

        $this->assertSame('بتوفّر أجرة عاملة بشهر', $k->selling_points[0]);
        $this->assertSame('غالية', $k->objections[0]['objection']);
        $this->assertSame('إي، 220 فولت', $k->faq[0]['answer']);
        $this->assertTrue($k->is_ready);
    }

    /**
     * والجاهزية تُغيّر ما يبيعه الوكيل فعلًا.
     *
     * هذا هو الفحص الذي يعني شيئًا: الصنف غير الجاهز لا يظهر في بحث الوكيل
     * أصلًا.
     */
    public function test_readiness_changes_what_the_agent_can_sell(): void
    {
        $search = fn () => app(SearchProductsTool::class)->handle(['query' => 'مكنسة']);

        $this->assertSame([], $search()['products'] ?? []);

        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload());

        $this->assertNotEmpty($search()['products'] ?? []);
    }

    /** وإطفاء الجاهزية يُخرجه من بحث الوكيل ثانيةً. */
    public function test_unsetting_readiness_hides_it_again(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload());

        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload(['is_ready' => 0]));

        $this->assertSame([], app(SearchProductsTool::class)->handle(['query' => 'مكنسة'])['products'] ?? []);
    }

    /**
     * ولا يُوسَم «جاهزًا» بلا نقطة بيعٍ واحدة.
     *
     * الجاهزية إذنٌ بالبيع؛ فوسمُه جاهزًا وهو فارغ يُخرجه للزبائن بلا ما
     * يُقال عنه، والوكيل مأمورٌ ألّا يرتجل — فيقف صامتًا حيث ظُنّ أنه مُعدّ.
     */
    public function test_it_refuses_readiness_without_a_single_selling_point(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload([
                'selling_points' => ['', ''],
            ]))
            ->assertSessionHasErrors('selling_points');

        $this->assertNull(ProductKnowledge::where('product_id', $this->product->id)->first());
    }

    /** والسطور الفارغة تُنظَّف قبل الحفظ. */
    public function test_blank_lines_are_dropped(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload([
                'selling_points' => ['نقطة حقيقية', '', '   '],
            ]));

        $this->assertSame(['نقطة حقيقية'],
            ProductKnowledge::where('product_id', $this->product->id)->firstOrFail()->selling_points);
    }

    /**
     * والاعتراض بلا ردّ لا يُحفظ.
     *
     * يقرؤه النموذج فيعرف الاعتراض ولا يملك جوابًا عليه.
     */
    public function test_a_half_filled_pair_is_dropped(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload([
                'objections' => [
                    ['objection' => 'غالية', 'response' => ''],
                    ['objection' => 'في أرخص', 'response' => 'بس مش بنفس الجودة'],
                ],
            ]));

        $saved = ProductKnowledge::where('product_id', $this->product->id)->firstOrFail()->objections;

        $this->assertCount(1, $saved);
        $this->assertSame('في أرخص', $saved[0]['objection']);
    }

    /** ومن حرّرها يُسجَّل. */
    public function test_the_editor_is_recorded(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai_agent.knowledge.update', $this->product), $this->payload());

        $this->assertSame(
            $this->admin()->id,
            ProductKnowledge::where('product_id', $this->product->id)->firstOrFail()->updated_by,
        );
    }

    /** وفلتر «غير جاهزة» يُظهر ما لم يُكتب له شيء. */
    public function test_the_not_ready_filter_finds_untouched_products(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.ai_agent.knowledge.index', ['ready' => 'no']))
            ->assertOk()
            ->assertSee('مكنسة بخارية', false);

        $this->actingAs($this->admin())
            ->get(route('admin.ai_agent.knowledge.index', ['ready' => 'yes']))
            ->assertOk()
            ->assertDontSee('مكنسة بخارية', false);
    }

    // ────────── الصلاحيات ──────────

    /** الشاشة محصورة بمدير النظام في مرحلة التجربة. */
    public function test_the_screen_is_admin_only_during_the_trial(): void
    {
        foreach (['manager', 'sales', 'affiliate'] as $role) {
            $this->actingAs($this->withRole($role))
                ->get(route('admin.ai_agent.knowledge.index'))
                ->assertForbidden();
        }
    }

    /** ويراها مدير النظام في قائمته. */
    public function test_the_sidebar_shows_it_to_the_system_admin(): void
    {
        $this->actingAs($this->admin());

        $titles = collect(AdminNavigation::groups())
            ->flatMap(fn (array $g) => collect($g['items'])->pluck('label'))->all();

        $this->assertContains('المعرفة البيعية للأصناف', $titles);
    }
}
