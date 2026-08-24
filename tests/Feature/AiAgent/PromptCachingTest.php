<?php

namespace Tests\Feature\AiAgent;

use App\Modules\AiAgent\Support\AnthropicClient;
use App\Modules\AiAgent\Support\TokenUsage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * التخزين المؤقّت للمُدخَل — وحسابُ ثمنه.
 *
 * الواجهة بلا ذاكرة: كل نداءٍ يحمل التعليمات والأدوات والتاريخ كاملًا. والدورة
 * الواحدة تنادي ثلاث مرّات أو أربعًا بتاريخٍ ينمو، فيُدفع ثمن الثابت مرارًا.
 *
 * والتخزين **مطابقةُ بادئة**: علامةٌ على كتلة تعني «خزّن كلّ ما قبلي». فتوضع
 * علامتان: واحدة بعد التعليمات (وقبلها الأدوات)، وأخرى عند آخر رسالة ليقرأ كلُّ
 * نداءٍ ما بناه سابقُه.
 */
class PromptCachingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_agent.api_key' => 'sk-test', 'ai_agent.model' => 'claude-sonnet-5']);
    }

    private function fakeOk(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'تمام']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);
    }

    /** @return array<string, mixed> */
    private function sentPayload(): array
    {
        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        return $sent;
    }

    /** التعليمات تُرسَل كتلةً عليها علامة التخزين — لا نصًّا خامًّا. */
    public function test_the_system_prompt_carries_a_cache_breakpoint(): void
    {
        $this->fakeOk();

        app(AnthropicClient::class)->messages('تعليمات', [['role' => 'user', 'content' => 'مرحبا']]);

        $system = $this->sentPayload()['system'];

        $this->assertSame('تعليمات', $system[0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $system[0]['cache_control']);
    }

    /**
     * وآخر رسالةٍ تحمل العلامة الثانية.
     *
     * بلا هذه، يُخزَّن الثابت وحده ويُعاد احتساب التاريخ كاملًا في كل نداء —
     * وهو الجزء الذي ينمو، أي الأغلى.
     */
    public function test_the_last_message_carries_a_cache_breakpoint(): void
    {
        $this->fakeOk();

        app(AnthropicClient::class)->messages('تعليمات', [
            ['role' => 'user', 'content' => 'مرحبا'],
            ['role' => 'assistant', 'content' => 'أهلًا'],
            ['role' => 'user', 'content' => 'بكم البدلة؟'],
        ]);

        $messages = $this->sentPayload()['messages'];

        $this->assertSame('بكم البدلة؟', $messages[2]['content'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $messages[2]['content'][0]['cache_control']);

        // وما قبلها يبقى بلا علامة: أربع علامات حدٌّ أقصى، ولا داعي لإنفاقها.
        $this->assertIsString($messages[0]['content']);
    }

    /** والعلامة تُوضع على آخر كتلة حين تكون الرسالة كتلًا (نتائج أدوات). */
    public function test_it_marks_the_last_block_of_a_block_message(): void
    {
        $this->fakeOk();

        app(AnthropicClient::class)->messages('تعليمات', [
            ['role' => 'user', 'content' => 'مرحبا'],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'a', 'content' => '{}'],
                ['type' => 'tool_result', 'tool_use_id' => 'b', 'content' => '{}'],
            ]],
        ]);

        $blocks = $this->sentPayload()['messages'][1]['content'];

        $this->assertArrayNotHasKey('cache_control', $blocks[0]);
        $this->assertSame(['type' => 'ephemeral'], $blocks[1]['cache_control']);
    }

    // ────────── التكلفة ──────────

    /**
     * التوكنز المخزَّنة تدخل الحساب بأسعارها.
     *
     * `input_tokens` بعد التفعيل **لا يشمل المخزَّن**؛ فحسابُ التكلفة منه وحده
     * يُنتج رقمًا أقلّ من الحقيقي — وهو أسوأ من لا رقم.
     */
    public function test_cached_tokens_are_priced(): void
    {
        config(['ai_agent.pricing' => ['claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00]]]);

        $usage = new TokenUsage;
        $usage->add(['usage' => [
            'input_tokens' => 1_000_000,
            'cache_creation_input_tokens' => 1_000_000,   // ×1.25
            'cache_read_input_tokens' => 1_000_000,       // ×0.10
            'output_tokens' => 1_000_000,
        ]]);

        // 3 + 3.75 + 0.30 + 15
        $this->assertSame('22.0500', $usage->cost());
    }

    /** والجمع تراكميّ عبر نداءات الدورة الواحدة. */
    public function test_usage_accumulates_across_calls(): void
    {
        $usage = new TokenUsage;
        $usage->add(['usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 100]]);
        $usage->add(['usage' => ['input_tokens' => 20, 'cache_read_input_tokens' => 200]]);

        $this->assertSame(30, $usage->input);
        $this->assertSame(300, $usage->cacheRead);
    }

    /** والأعمدة تُكتب في سجلّ الدورة. */
    public function test_it_exposes_the_run_columns(): void
    {
        $usage = new TokenUsage;
        $usage->add(['usage' => [
            'input_tokens' => 1, 'cache_creation_input_tokens' => 2,
            'cache_read_input_tokens' => 3, 'output_tokens' => 4,
        ]]);

        $this->assertSame(
            ['input_tokens' => 1, 'cache_write_tokens' => 2, 'cache_read_tokens' => 3, 'output_tokens' => 4],
            collect($usage->attributes())->except('cost')->all(),
        );
    }
}
