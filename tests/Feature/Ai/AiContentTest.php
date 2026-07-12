<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Modules\Ai\Models\AiGenerationLog;
use App\Modules\Ai\Services\AiContentService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Support\Contracts\Ai\AiContentRequest;
use App\Support\Integrations\Ai\NullAiContentProvider;
use App\Support\Integrations\Ai\OpenAiContentProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['ai.default' => 'null']); // لا شبكة في الاختبارات
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_generates_suggestion_and_logs_usage_without_touching_product(): void
    {
        $product = Product::factory()->active()->create(['name' => 'اسم أصلي', 'visibility' => 'visible']);

        $response = $this->actingAs($this->actor('manager'))->postJson(route('admin.ai.content.generate'), [
            'type' => 'title_ar',
            'action' => 'generate',
            'locale' => 'ar',
            'inputs' => ['name' => 'كرسي مكتب', 'brand' => 'أثاث'],
            'product_id' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonStructure(['suggestion', 'status', 'model', 'total_tokens']);

        // المحتوى اقتراح فقط — لم يُكتب أي حقل في المنتج.
        $this->assertSame('اسم أصلي', $product->fresh()->name);

        // سُجّل الاستخدام بلا أسرار.
        $log = AiGenerationLog::first();
        $this->assertNotNull($log);
        $this->assertSame('title_ar', $log->type);
        $this->assertSame('null', $log->provider);
        $this->assertSame($product->id, $log->product_id);
        $this->assertSame('success', $log->status);
    }

    public function test_requires_permission(): void
    {
        $this->actingAs($this->actor('accountant')) // لا يملك ai.content.use
            ->postJson(route('admin.ai.content.generate'), ['type' => 'title_ar'])
            ->assertForbidden();
    }

    public function test_rate_limit_enforced(): void
    {
        config(['ai.rate_limit' => 1]);
        $actor = $this->actor('manager');

        $this->actingAs($actor)->postJson(route('admin.ai.content.generate'), ['type' => 'title_ar', 'inputs' => ['name' => 'x']])->assertOk();
        $this->actingAs($actor)->postJson(route('admin.ai.content.generate'), ['type' => 'title_ar', 'inputs' => ['name' => 'x']])->assertStatus(422);
    }

    public function test_null_provider_does_not_invent_specs(): void
    {
        $result = (new NullAiContentProvider)->generate(new AiContentRequest('specs', 'generate', 'ar', ['name' => 'منتج']));
        // بلا مواصفات مُعطاة ⇒ لا يخترع شيئًا.
        $this->assertStringContainsString('لا مواصفات', $result->content);
    }

    public function test_openai_falls_back_gracefully_without_key(): void
    {
        config(['services.openai.key' => null]);
        $result = (new OpenAiContentProvider)->generate(new AiContentRequest('title_ar', 'generate', 'ar', ['name' => 'كرسي']));
        // بلا مفتاح ⇒ تراجع رشيق إلى القالب الآمن (بلا استثناء/شبكة).
        $this->assertNotEmpty($result->content);
    }

    public function test_service_rejects_unknown_type(): void
    {
        $this->expectException(ValidationException::class);
        app(AiContentService::class)->generate(type: 'bogus');
    }
}
