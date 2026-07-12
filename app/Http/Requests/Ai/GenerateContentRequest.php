<?php

namespace App\Http\Requests\Ai;

use App\Modules\Ai\Services\AiContentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * تحقّق طلب توليد محتوى بالذكاء الاصطناعي (Phase 6 / ADR-044).
 * التحقّق في Form Request لا داخل المتحكّم (اصطلاحات المشروع).
 */
class GenerateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ai.content.use') ?? false;
    }

    /** نقطة نهاية JSON (AJAX) — نرجع أخطاء التحقّق JSON دائمًا (خارج نطاق api/*). */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('بيانات غير صالحة.'),
            'errors' => $validator->errors(),
        ], 422));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(AiContentService::TYPES)],
            'action' => ['nullable', 'string', Rule::in(AiContentService::ACTIONS)],
            'locale' => ['nullable', 'string', Rule::in(['ar', 'en'])],
            'tone' => ['nullable', 'string', 'max:40'],
            'inputs' => ['nullable', 'array'],
            'inputs.name' => ['nullable', 'string', 'max:255'],
            'inputs.brand' => ['nullable', 'string', 'max:255'],
            'inputs.category' => ['nullable', 'string', 'max:255'],
            'inputs.notes' => ['nullable', 'string', 'max:2000'],
            'inputs.specifications' => ['nullable', 'array'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'use_image' => ['nullable', 'boolean'],
        ];
    }
}
