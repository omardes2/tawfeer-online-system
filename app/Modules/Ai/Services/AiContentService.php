<?php

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiGenerationLog;
use App\Modules\Catalog\Models\Product;
use App\Support\Contracts\Ai\AiContentProviderInterface;
use App\Support\Contracts\Ai\AiContentRequest;
use App\Support\Contracts\Ai\AiContentResult;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * مساعد محتوى المنتجات بالذكاء الاصطناعي (Phase 6 / ADR-044).
 *
 * **المحتوى المُولَّد اقتراح دائمًا** — لا يكتب هذه الخدمة أي حقل منتج ولا تنشر شيئًا؛
 * تُرجِع نصًّا للموظّف ليراجع/يعدّل/يطبّق يدويًا. الاتصال بالمزوّد عبر العقد المحقون
 * (لا استدعاء من متحكّم/نموذج). حدّ معدّل + تسجيل استخدام بلا أسرار + تراجع رشيق.
 */
class AiContentService
{
    /** أنواع المحتوى المدعومة (المتطلّب). */
    public const TYPES = [
        'title_ar', 'title_en', 'short_description', 'description', 'features', 'specs',
        'seo_title', 'meta_description', 'keywords', 'social_caption', 'whatsapp',
    ];

    public const ACTIONS = ['generate', 'regenerate', 'shorten', 'expand', 'tone'];

    public function __construct(private readonly AiContentProviderInterface $provider) {}

    /**
     * توليد اقتراح محتوى. لا يمسّ المنتج إطلاقًا.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function generate(
        string $type,
        string $action = 'generate',
        string $locale = 'ar',
        array $inputs = [],
        ?string $tone = null,
        ?Product $product = null,
        ?string $imageUrl = null,
        ?User $actor = null,
    ): AiContentResult {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('نوع محتوى غير مدعوم.')]);
        }
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => __('إجراء غير مدعوم.')]);
        }

        $this->assertRateLimit($actor);

        $request = new AiContentRequest($type, $action, $locale, $inputs, $tone, $imageUrl);
        $result = $this->provider->generate($request);

        $this->log($request, $result, $product, $actor);

        return $result;
    }

    /**
     * توليد حزمة محتوى منتج كاملة بنداء واحد (زر «توليد بالذكاء الاصطناعي»).
     * **اقتراح فقط — لا يمسّ المنتج**. يُرجِع النتيجة (محتوى JSON بالحقول).
     *
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $context  categories/tags المتاحة
     */
    public function generateBundle(
        string $locale = 'ar',
        array $inputs = [],
        array $context = [],
        ?Product $product = null,
        ?string $imageUrl = null,
        ?User $actor = null,
    ): AiContentResult {
        $this->assertRateLimit($actor);

        $request = new AiContentRequest('bundle', 'generate', $locale, $inputs, null, $imageUrl);
        $result = $this->provider->generateBundle($request, $context);

        $this->log($request, $result, $product, $actor);

        return $result;
    }

    private function assertRateLimit(?User $actor): void
    {
        $key = 'ai-content:'.($actor?->id ?? 'guest');
        $max = (int) config('ai.rate_limit', 20);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                'rate' => __('تجاوزت حدّ الاستخدام. حاول بعد :s ثانية.', ['s' => RateLimiter::availableIn($key)]),
            ]);
        }
        RateLimiter::hit($key, 60);
    }

    private function log(AiContentRequest $request, AiContentResult $result, ?Product $product, ?User $actor): void
    {
        AiGenerationLog::create([
            'user_id' => $actor?->id ?? auth()->id(),
            'product_id' => $product?->id,
            'type' => $request->type,
            'action' => $request->action,
            'locale' => $request->locale,
            'provider' => $this->provider->name(),
            'model' => $result->model,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'total_tokens' => $result->totalTokens(),
            'status' => $result->status,
            'error' => $result->error,
            'created_at' => now(),
        ]);
    }
}
