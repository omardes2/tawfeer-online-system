<?php

namespace App\Support\Integrations\Ai;

use App\Support\Contracts\Ai\AiContentProviderInterface;
use App\Support\Contracts\Ai\AiContentRequest;
use App\Support\Contracts\Ai\AiContentResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * مزوّد OpenAI لمحتوى المنتجات (Phase 6 / ADR-044، المبدأ 13).
 * **كل منطق OpenAI محصور هنا**؛ المفتاح من config('services.openai') (env) لا من الكود.
 * معالجة مهلة/إعادة محاولة/تراجع رشيق. لا يُستدعى مباشرةً من متحكم/نموذج.
 */
class OpenAiContentProvider implements AiContentProviderInterface
{
    public function generate(AiContentRequest $request): AiContentResult
    {
        $key = config('services.openai.key');
        $model = config('ai.model', 'gpt-4o-mini');

        if (empty($key)) {
            // بلا مفتاح ⇒ تراجع رشيق إلى القالب الآمن.
            return (new NullAiContentProvider)->generate($request);
        }

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('ai.timeout', 30))
                ->retry((int) config('ai.max_retries', 2), 500)
                ->baseUrl((string) config('services.openai.base_url'))
                ->post('/chat/completions', [
                    'model' => $model,
                    'messages' => $this->messages($request),
                    'temperature' => 0.7,
                ]);

            if ($response->failed()) {
                return $this->fallback($request, 'http '.$response->status());
            }

            $data = $response->json();
            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
            $usage = $data['usage'] ?? [];

            return new AiContentResult(
                content: $content !== '' ? $content : (new NullAiContentProvider)->generate($request)->content,
                model: (string) ($data['model'] ?? $model),
                promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
                completionTokens: (int) ($usage['completion_tokens'] ?? 0),
                status: 'success',
            );
        } catch (Throwable $e) {
            return $this->fallback($request, mb_substr($e->getMessage(), 0, 200));
        }
    }

    /** تراجع رشيق: قالب آمن مع تعليم الحالة (fallback). */
    private function fallback(AiContentRequest $request, string $error): AiContentResult
    {
        $draft = (new NullAiContentProvider)->generate($request);

        return new AiContentResult(
            content: $draft->content,
            model: 'openai:fallback',
            status: 'fallback',
            error: $error,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function messages(AiContentRequest $request): array
    {
        $system = 'You write e-commerce product content. Use ONLY the provided facts; '
            .'do NOT invent specifications, measurements, or claims not supplied or clearly visible. '
            .'Return only the requested content, no preamble. Language: '.($request->locale === 'ar' ? 'Arabic' : 'English').'.';
        if ($request->tone) {
            $system .= ' Tone: '.$request->tone.'.';
        }
        $user = 'Task: '.$request->type.' ('.$request->action.")\nInputs:\n".json_encode($request->inputs, JSON_UNESCAPED_UNICODE);

        $content = [['type' => 'text', 'text' => $user]];
        if ($request->imageUrl && config('ai.vision')) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $request->imageUrl]];
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $content],
        ];
    }

    public function name(): string
    {
        return 'openai';
    }
}
