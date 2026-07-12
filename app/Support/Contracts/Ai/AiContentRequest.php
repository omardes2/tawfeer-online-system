<?php

namespace App\Support\Contracts\Ai;

/**
 * طلب توليد محتوى بالذكاء الاصطناعي (Phase 6 / ADR-044). DTO ثابت — لا أسرار.
 */
final class AiContentRequest
{
    /**
     * @param  string  $type  نوع المحتوى (title_ar/title_en/short_description/description/features/specs/seo_title/meta_description/keywords/social_caption/whatsapp)
     * @param  string  $action  الإجراء (generate/regenerate/shorten/expand/tone)
     * @param  string  $locale  ar|en
     * @param  array<string, mixed>  $inputs  مدخلات المنتج (name/category/brand/specs/attributes/notes...)
     * @param  ?string  $tone  نبرة اختيارية (professional/friendly/marketing...)
     * @param  ?string  $imageUrl  رابط صورة المنتج المحدّدة (تحليل بصري بإذن صريح فقط)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $action = 'generate',
        public readonly string $locale = 'ar',
        public readonly array $inputs = [],
        public readonly ?string $tone = null,
        public readonly ?string $imageUrl = null,
    ) {}
}
