<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * إكمال الملف بعد الدخول الاجتماعي (Phase 3.5): الحقول المطلوبة قبل الشراء.
 */
class CompleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:40'],
            'birth_date' => ['required', 'date', 'before:today'],
            'preferred_locale' => ['required', Rule::in(config('storefront.locales', ['ar', 'en']))],
            'communication_preferences' => ['nullable', 'array'],
            'communication_preferences.whatsapp' => ['nullable', 'boolean'],
            'communication_preferences.email' => ['nullable', 'boolean'],
            'communication_preferences.sms' => ['nullable', 'boolean'],
            'communication_preferences.push' => ['nullable', 'boolean'],
        ];
    }
}
