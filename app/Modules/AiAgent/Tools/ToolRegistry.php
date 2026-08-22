<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\AiAgent\Models\AgentRun;
use App\Modules\AiAgent\Models\AgentToolCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * سجلّ الأدوات المتاحة للوكيل، ومنفّذها الوحيد.
 *
 * كل استدعاءٍ يمرّ من `call` فيُسجَّل في `agent_tool_calls` — نجح أو فشل. وهذا
 * ما يجعل المبدأ الأول **قابلًا للإثبات** لا وعدًا: إن قال الوكيل سعرًا خاطئًا،
 * هنا يُعرَف أجاء من أداةٍ أم اخترعه.
 *
 * والخطأ لا يُرفع إلى مشغّل الوكيل: يُعاد إلى النموذج كنتيجةٍ فيها `error`،
 * فيقرأها ويتصرّف (يسأل الزبون أو يحوّل) بدل أن تنهار الدورة كلّها على أداةٍ
 * واحدة.
 */
class ToolRegistry
{
    /** @var array<string, ToolContract> */
    private array $tools = [];

    /** @param  iterable<ToolContract>  $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(ToolContract $tool): self
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return array<string, ToolContract> */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * تعريف الأدوات كما ترسله واجهة النموذج.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (ToolContract $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->schema(),
        ], $this->tools));
    }

    /**
     * تنفيذ أداةٍ وتسجيلها.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, ?AgentRun $run = null): array
    {
        $startedAt = microtime(true);

        if (! $this->has($name)) {
            $result = ['error' => 'unknown_tool', 'message' => 'أداة غير معروفة.'];
            $this->record($run, $name, $arguments, $result, 'error', $startedAt);

            return $result;
        }

        try {
            $result = $this->tools[$name]->handle($arguments);
            $status = 'ok';
        } catch (Throwable $e) {
            // الخطأ يُعاد إلى النموذج لا يُرفع: أداةٌ واحدة فشلت لا تُسقط
            // الدورة، والنموذج مأمورٌ أن يسأل أو يحوّل عند الفشل.
            Log::warning('ai_agent.tool.failed', ['tool' => $name, 'error' => $e->getMessage()]);
            $result = ['error' => 'tool_failed', 'message' => 'تعذّر تنفيذ الأداة.'];
            $status = 'error';
        }

        $this->record($run, $name, $arguments, $result, $status, $startedAt);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $result
     */
    private function record(?AgentRun $run, string $name, array $arguments, array $result, string $status, float $startedAt): void
    {
        if ($run === null) {
            return;
        }

        AgentToolCall::create([
            'agent_run_id' => $run->id,
            'tool_name' => $name,
            'arguments' => $arguments,
            'result' => $result,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
