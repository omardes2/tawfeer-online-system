<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

/**
 * فتح استثناء توصيل (Phase 4.3 / ADR-039).
 */
class OpenExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:shipping.delivery.manage على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'exists:delivery_exception_categories,code'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
