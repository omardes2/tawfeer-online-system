<?php

namespace App\Modules\AiAgent\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * نداء واجهة النموذج — طبقةٌ رقيقة عمدًا.
 *
 * لا تفسّر الردّ ولا تقرّر شيئًا: تبني الطلب، وترمي عند الفشل، وتعيد الجسم
 * كما جاء. التفسير كلّه في `RunSalesAgent` كي يبقى موضعٌ واحد يُقرأ فيه سلوك
 * الوكيل.
 *
 * والمهلة قصيرة (30 ثانية): الزبون ينتظر على واتساب، ونداءٌ يتجاوزها فشلٌ
 * عمليًّا ولو نجح — فالردّ بعد دقيقةٍ يصل إلى محادثةٍ انتهت.
 */
class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const VERSION = '2023-06-01';

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function messages(string $system, array $messages, array $tools = []): array
    {
        $key = config('ai_agent.api_key');

        if (blank($key)) {
            throw new RuntimeException('ANTHROPIC_API_KEY غير مضبوط.');
        }

        $payload = [
            'model' => config('ai_agent.model'),
            'max_tokens' => (int) config('ai_agent.max_tokens', 1024),
            // التعليمات كتلةً لا نصًّا: الكتلة وحدها تقبل `cache_control`.
            'system' => [$this->cached(['type' => 'text', 'text' => $system])],
            'messages' => $this->withHistoryBreakpoint($messages),
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => self::VERSION,
            'content-type' => 'application/json',
        ])->timeout((int) config('ai_agent.timeout_seconds', 30))
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            // نصُّ الخطأ يُحفظ في `agent_runs.error`؛ ويُقصّ فلا يُغرق العمود
            // بجسم استجابةٍ كامل.
            throw new RuntimeException('نداء النموذج فشل ('.$response->status().'): '
                .mb_substr((string) $response->body(), 0, 500));
        }

        return $response->json() ?? [];
    }

    /**
     * علامة التخزين المؤقّت على كتلة.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function cached(array $block): array
    {
        return $block + ['cache_control' => ['type' => 'ephemeral']];
    }

    /**
     * نقطة تخزينٍ ثانية عند آخر رسالة.
     *
     * التخزين **مطابقةُ بادئة**: ما قبل العلامة يُخزَّن، وما بعدها يُحتسب كاملًا.
     * فوضعُها عند آخر رسالة يجعل كلّ نداءٍ يقرأ ما بناه النداء السابق — والدورة
     * الواحدة تنادي النموذج ثلاث مرّات أو أربعًا بتاريخٍ ينمو في كلّ مرّة، فيُدفع
     * ثمن التاريخ نفسه مرارًا بلا هذه العلامة.
     *
     * وهي تنفع بين الدورات أيضًا: زبونٌ يكتب ثانيةً خلال دقائق يجد تاريخه
     * مخزّنًا.
     *
     * ونصُّ السلسلة يُحوَّل إلى كتلة — `cache_control` لا توضع على نصٍّ خام.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function withHistoryBreakpoint(array $messages): array
    {
        $last = array_key_last($messages);

        if ($last === null) {
            return $messages;
        }

        $content = $messages[$last]['content'] ?? null;

        if (is_string($content)) {
            $messages[$last]['content'] = [$this->cached(['type' => 'text', 'text' => $content])];

            return $messages;
        }

        if (! is_array($content) || $content === []) {
            return $messages;
        }

        $lastBlock = array_key_last($content);

        if (is_array($content[$lastBlock])) {
            $content[$lastBlock] = $this->cached($content[$lastBlock]);
            $messages[$last]['content'] = $content;
        }

        return $messages;
    }
}
