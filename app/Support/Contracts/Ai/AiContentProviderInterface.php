<?php

namespace App\Support\Contracts\Ai;

/**
 * عقد مزوّد محتوى الذكاء الاصطناعي (Phase 6 / ADR-044، المبدأ 13).
 * لا يُستدعى مزوّد مباشرةً من متحكم/نموذج — فقط عبر الخدمة/طبقة التكامل.
 */
interface AiContentProviderInterface
{
    public function generate(AiContentRequest $request): AiContentResult;

    public function name(): string;
}
