<?php

namespace App\Http\Requests\Ai;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * تحقّق زر «توليد بالذكاء الاصطناعي» (حزمة محتوى منتج كاملة) — Production.
 */
class GenerateBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ai.content.use') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'inputs' => ['nullable', 'array'],
            'inputs.name' => ['nullable', 'string', 'max:255'],
            'inputs.brand' => ['nullable', 'string', 'max:255'],
            'inputs.category' => ['nullable', 'string', 'max:255'],
            'inputs.notes' => ['nullable', 'string', 'max:2000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'use_image' => ['nullable', 'boolean'],
        ];
    }

    /** نقطة JSON (AJAX) — أخطاء التحقّق JSON دائمًا (خارج api/*). */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('بيانات غير صالحة.'),
            'errors' => $validator->errors(),
        ], 422));
    }
}
