<?php

namespace App\Support\Contracts\Ai;

/**
 * نتيجة توليد محتوى بالذكاء الاصطناعي (Phase 6 / ADR-044). **اقتراح دائمًا** — لا نشر تلقائي.
 */
final class AiContentResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly string $status = 'success', // success | failed | timeout | fallback
        public readonly ?string $error = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function ok(): bool
    {
        return $this->status === 'success';
    }
}
