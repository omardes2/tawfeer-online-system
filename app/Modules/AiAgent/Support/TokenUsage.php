<?php

namespace App\Modules\AiAgent\Support;

/**
 * توكنز دورةٍ واحدة وتكلفتها.
 *
 * أربعة أعداد لا اثنان، لأن التخزين المؤقّت يقسم المُدخَل بثلاثة أسعار:
 *
 * | القسم | السعر |
 * |---|---|
 * | جديد (`input_tokens`) | كامل |
 * | كتابةٌ في المخزن (`cache_creation_input_tokens`) | ١٫٢٥ ضعفًا |
 * | قراءةٌ منه (`cache_read_input_tokens`) | العُشر |
 *
 * و`input_tokens` بعد تفعيل التخزين **لا يشمل المخزَّن**، فحسابُ التكلفة منه
 * وحده يُنتج رقمًا أقلّ من الحقيقي — وهو أسوأ من لا رقم: يُطمئن وهو خاطئ.
 *
 * والجمع تراكميّ عبر نداءات الدورة الواحدة: الدورة تنادي النموذج مرّةً لكل
 * جولة أدوات، وتكلفتها مجموعها لا آخرها.
 */
class TokenUsage
{
    /** كتابة المخزن أغلى من المُدخَل العاديّ بهذه النسبة. */
    private const WRITE_MULTIPLIER = 1.25;

    /** والقراءة منه أرخص بهذه النسبة. */
    private const READ_MULTIPLIER = 0.10;

    public int $input = 0;

    public int $cacheWrite = 0;

    public int $cacheRead = 0;

    public int $output = 0;

    /** @param  array<string, mixed>  $response */
    public function add(array $response): void
    {
        $this->input += (int) data_get($response, 'usage.input_tokens', 0);
        $this->cacheWrite += (int) data_get($response, 'usage.cache_creation_input_tokens', 0);
        $this->cacheRead += (int) data_get($response, 'usage.cache_read_input_tokens', 0);
        $this->output += (int) data_get($response, 'usage.output_tokens', 0);
    }

    /**
     * التكلفة بالدولار.
     *
     * الأسعار في `config/ai_agent.php` لا في الكود: تتغيّر بقرار المزوّد لا
     * بقرارنا، وتغييرها يجب ألّا يحتاج نشرًا. ونموذجٌ لا سعر له يُحسب على
     * `default` — تقديرٌ خاطئ أنفع من صفرٍ يخفي الإنفاق كلّه.
     */
    public function cost(): string
    {
        $prices = config('ai_agent.pricing');
        $model = (string) config('ai_agent.model');
        $rate = $prices[$model] ?? $prices['default'];

        $in = (float) $rate['input'];

        $cost = ($this->input * $in
            + $this->cacheWrite * $in * self::WRITE_MULTIPLIER
            + $this->cacheRead * $in * self::READ_MULTIPLIER
            + $this->output * (float) $rate['output']) / 1_000_000;

        return number_format($cost, 4, '.', '');
    }

    /** @return array<string, int|string> */
    public function attributes(): array
    {
        return [
            'input_tokens' => $this->input,
            'cache_write_tokens' => $this->cacheWrite,
            'cache_read_tokens' => $this->cacheRead,
            'output_tokens' => $this->output,
            'cost' => $this->cost(),
        ];
    }
}
