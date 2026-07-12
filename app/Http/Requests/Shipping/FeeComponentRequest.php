<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * إضافة مكوّن رسم شحنة (Phase 4.3 / ADR-039) — مستقلّ عن المزوّد.
 */
class FeeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:shipping.delivery.fees على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(config('delivery.fees.types'))],
            'amount' => ['required', 'numeric'],
            'owner' => ['nullable', Rule::in(config('delivery.fees.owners'))],
            'source' => ['nullable', Rule::in(config('delivery.fees.sources'))],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
