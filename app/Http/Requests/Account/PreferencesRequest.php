<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preferred_locale' => ['nullable', Rule::in(config('storefront.locales', ['ar', 'en']))],
            'preferred_branch_id' => ['nullable', 'exists:branches,id'],
            'communication_preferences' => ['nullable', 'array'],
            'communication_preferences.whatsapp' => ['nullable', 'boolean'],
            'communication_preferences.email' => ['nullable', 'boolean'],
            'communication_preferences.sms' => ['nullable', 'boolean'],
            'communication_preferences.push' => ['nullable', 'boolean'],
        ];
    }
}
