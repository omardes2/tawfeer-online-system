<?php

namespace App\Support\Contracts\Ai;

/**
 * عقد مزوّد محتوى الذكاء الاصطناعي (Phase 6 / ADR-044، المبدأ 13).
 * لا يُستدعى مزوّد مباشرةً من متحكم/نموذج — فقط عبر الخدمة/طبقة التكامل.
 */
interface AiContentProviderInterface
{
    public function generate(AiContentRequest $request): AiContentResult;

    /**
     * توليد حزمة محتوى منتج كاملة بنداء واحد (زر «توليد بالذكاء الاصطناعي»).
     * تُرجِع النتيجة محتوى JSON بالحقول: title/short_description/description/features/
     * specs/seo_title/meta_description/keywords/tags/category. **اقتراح فقط — لا يُنشر**.
     *
     * @param  array<string, mixed>  $context  categories/tags المتاحة لاقتراح المطابقة
     */
    public function generateBundle(AiContentRequest $request, array $context = []): AiContentResult;

    public function name(): string;
}
