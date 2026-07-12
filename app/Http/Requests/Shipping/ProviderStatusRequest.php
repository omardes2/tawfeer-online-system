<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

/**
 * استيعاب حالة مزوّد خام (webhook/مزامنة يدوية) — Phase 4.3 / ADR-038.
 * التعيين لحالة قانونية يقع في الـDriver، لا هنا.
 */
class ProviderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:shipping.delivery.sync على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider_status' => ['required', 'string', 'max:60'],
            'event_id' => ['nullable', 'string', 'max:120'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
