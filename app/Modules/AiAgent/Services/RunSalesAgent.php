<?php

namespace App\Modules\AiAgent\Services;

use App\Modules\AiAgent\Models\AgentRun;
use App\Modules\AiAgent\Support\AnthropicClient;
use App\Modules\AiAgent\Tools\ToolRegistry;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Services\OutboundMessageService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * دورةٌ واحدة من دورات الوكيل: من رسائل الزبون إلى ردٍّ مُرسَل.
 *
 * أربعة قرارات تحكم هذا الملف:
 *
 * 1. **الدورة محدودة بسقف استدعاءات.** نموذجٌ لا يجد جوابًا يعيد استدعاء
 *    الأدوات بلا نهاية — فيُنفق المال ويتأخّر الردّ ولا يصل الزبون إلى شيء.
 *    عند السقف يُحوَّل إلى موظفة: محاولةٌ سابعة لن تنجح حيث فشلت ستّ.
 *
 * 2. **كل مخرجٍ ينتهي إلى صفٍّ في `agent_runs`** — نجح أو فشل أو حُوّل. وحتى
 *    الفشل قبل أول نداء يُسجَّل، وإلّا صار الفشل الصامت غير مرئيّ تمامًا: لا
 *    ردّ ولا سجلّ ولا سبب.
 *
 * 3. **الفشل يُحوِّل ولا يصمت.** الاستثناء يعني أن الزبون كتب ولم يصله شيء؛
 *    فتُسلَّم المحادثة إلى إنسانٍ مع اعتذارٍ قصير بدل أن تُترك معلّقة.
 *
 * 4. **الاعتذار قد يفشل هو الآخر** (النافذة أُغلقت، أو واتساب لا يستجيب)،
 *    فإرساله محاطٌ بحمايته الخاصّة: فشلُ الاعتذار يجب ألّا يمنع التحويل — وإلّا
 *    بقيت المحادثة عند وكيلٍ معطوب.
 */
class RunSalesAgent
{
    public function __construct(
        private readonly AnthropicClient $client,
        private readonly SalesAgentPrompt $prompt,
        private readonly ToolRegistry $tools,
        private readonly OutboundMessageService $outbound,
        private readonly HandoffService $handoff,
    ) {}

    /**
     * @param  array<int, int>  $triggerMessageIds  الرسائل المجمّعة التي أطلقت الدورة
     */
    public function handle(Conversation $conversation, array $triggerMessageIds = []): AgentRun
    {
        $startedAt = microtime(true);
        $model = (string) config('ai_agent.model');
        $inputTokens = 0;
        $outputTokens = 0;

        $run = AgentRun::create([
            'conversation_id' => $conversation->id,
            'trigger_message_ids' => $triggerMessageIds,
            'model' => $model,
            'outcome' => 'failed',   // يُصحَّح عند النجاح؛ فالانقطاع يترك أثرًا لا فراغًا
            'created_at' => now(),
        ]);

        try {
            $messages = $this->prompt->history($conversation);

            if ($messages === []) {
                return $this->close($run, 'silent', $startedAt, 0, 0);
            }

            $maxCalls = (int) config('ai_agent.max_tool_calls', 6);
            $calls = 0;

            while (true) {
                $response = $this->client->messages(
                    $this->prompt->system(),
                    $messages,
                    $this->tools->definitions(),
                );

                $inputTokens += (int) data_get($response, 'usage.input_tokens', 0);
                $outputTokens += (int) data_get($response, 'usage.output_tokens', 0);

                $content = data_get($response, 'content', []);
                $toolUses = $this->toolUses($content);

                if ($toolUses === []) {
                    return $this->reply($conversation, $run, $content, $startedAt, $inputTokens, $outputTokens);
                }

                $calls += count($toolUses);

                if ($calls > $maxCalls) {
                    // السقف تجاوزَته الدورة، فلا نتيجة بيدنا نردّ بها.
                    $this->handoff->handoff($conversation, 'tool_limit', $this->apology());

                    return $this->close($run, 'escalated', $startedAt, $inputTokens, $outputTokens);
                }

                $messages[] = ['role' => 'assistant', 'content' => $content];
                $messages[] = ['role' => 'user', 'content' => $this->results($toolUses, $run)];
            }
        } catch (Throwable $e) {
            Log::error('ai_agent.run.failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $this->handoff->handoff($conversation, 'agent_error', $this->apology());

            return $this->close($run, 'failed', $startedAt, $inputTokens, $outputTokens, $e->getMessage());
        }
    }

    /**
     * إرسال الردّ النصّي.
     *
     * ردٌّ بلا نصّ ليس خطأً تقنيًّا — النموذج قد يكتفي بنتيجة أداة — لكنه من
     * زاوية الزبون صمتٌ تامّ بعد سؤاله. فيُحوَّل.
     *
     * @param  array<int, mixed>  $content
     */
    private function reply(
        Conversation $conversation,
        AgentRun $run,
        array $content,
        float $startedAt,
        int $inputTokens,
        int $outputTokens,
    ): AgentRun {
        $text = $this->text($content);

        if ($text === '') {
            $this->handoff->handoff($conversation, 'empty_reply', $this->apology());

            return $this->close($run, 'escalated', $startedAt, $inputTokens, $outputTokens);
        }

        $this->outbound->sendText($conversation, $text);

        return $this->close($run, 'replied', $startedAt, $inputTokens, $outputTokens);
    }

    /**
     * كتل استدعاء الأدوات في ردّ النموذج.
     *
     * @param  array<int, mixed>  $content
     * @return array<int, array<string, mixed>>
     */
    private function toolUses(array $content): array
    {
        return array_values(array_filter(
            $content,
            fn ($block) => is_array($block) && ($block['type'] ?? null) === 'tool_use',
        ));
    }

    /**
     * تنفيذ الأدوات وإعادة نتائجها بصيغة الواجهة.
     *
     * @param  array<int, array<string, mixed>>  $toolUses
     * @return array<int, array<string, mixed>>
     */
    private function results(array $toolUses, AgentRun $run): array
    {
        $results = [];

        foreach ($toolUses as $use) {
            $result = $this->tools->call(
                (string) ($use['name'] ?? ''),
                (array) ($use['input'] ?? []),
                $run,
            );

            $results[] = [
                'type' => 'tool_result',
                'tool_use_id' => $use['id'] ?? '',
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'is_error' => isset($result['error']),
            ];
        }

        return $results;
    }

    /**
     * النصّ من كتل الردّ.
     *
     * @param  array<int, mixed>  $content
     */
    private function text(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $parts[] = trim((string) ($block['text'] ?? ''));
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }

    private function apology(): string
    {
        return (string) config('ai_agent.handoff_message');
    }

    /** ختم السجلّ بالتكلفة والزمن — الطريق الوحيد للخروج من `handle`. */
    private function close(
        AgentRun $run,
        string $outcome,
        float $startedAt,
        int $inputTokens,
        int $outputTokens,
        ?string $error = null,
    ): AgentRun {
        // السجلّ يمنع `update` عمدًا (append-only)، فيُكتب بالاستعلام مباشرةً
        // مرّةً واحدة عند الختم — لا تعديلًا لسجلٍّ منشور بل إتمامًا لكتابته.
        $attributes = [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $this->cost($inputTokens, $outputTokens),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'outcome' => $outcome,
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
        ];

        $run->newQuery()->whereKey($run->getKey())->toBase()->update($attributes);

        return $run->forceFill($attributes)->syncOriginal();
    }

    /**
     * تكلفة الاستدعاء بالدولار.
     *
     * الأسعار في `config/ai_agent.php` لا في الكود: تتغيّر بقرار المزوّد لا
     * بقرارنا، وتغييرها يجب ألّا يحتاج نشرًا. ونموذجٌ لا سعر له يُحسب على
     * `default` — تقديرٌ خاطئ أنفع من صفرٍ يخفي الإنفاق كلّه.
     */
    private function cost(int $inputTokens, int $outputTokens): string
    {
        $prices = config('ai_agent.pricing');
        $model = (string) config('ai_agent.model');
        $rate = $prices[$model] ?? $prices['default'];

        $cost = ($inputTokens / 1_000_000) * (float) $rate['input']
            + ($outputTokens / 1_000_000) * (float) $rate['output'];

        return number_format($cost, 4, '.', '');
    }
}
