<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Modules\Ai\Models\AiGenerationLog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['ai.default' => 'null']); // لا شبكة
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_generate_bundle_returns_all_fields_and_matches_category_tag(): void
    {
        $category = Category::factory()->create(['name' => 'كراسي']);
        $tag = ProductTag::create(['name' => 'أثاث', 'slug' => 'furniture']);
        $product = Product::factory()->active()->create(['name' => 'اسم أصلي', 'visibility' => 'visible']);

        $response = $this->actingAs($this->actor('manager'))->postJson(route('admin.ai.content.bundle'), [
            'locale' => 'ar',
            'inputs' => ['name' => 'كرسي مكتب', 'brand' => 'أثاث', 'category' => 'كراسي', 'notes' => 'مريح'],
            'product_id' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonStructure([
                'fields' => ['short_description', 'description', 'seo_title', 'meta_description', 'keywords'],
                'category_id', 'tag_ids', 'status', 'model', 'total_tokens',
            ]);

        // الفئة المقترحة طابقت فئة موجودة.
        $this->assertSame($category->id, $response->json('category_id'));
        // الوسم المقترح (من العلامة "أثاث") طابق وسمًا موجودًا.
        $this->assertContains($tag->id, $response->json('tag_ids'));

        // المنتج لم يُمَسّ.
        $this->assertSame('اسم أصلي', $product->fresh()->name);

        // سُجّل الاستخدام كـ bundle.
        $log = AiGenerationLog::latest('id')->first();
        $this->assertSame('bundle', $log->type);
        $this->assertSame('null', $log->provider);
    }

    public function test_bundle_requires_permission(): void
    {
        $this->actingAs($this->actor('accountant'))
            ->postJson(route('admin.ai.content.bundle'), ['inputs' => ['name' => 'x']])
            ->assertForbidden();
    }
}
