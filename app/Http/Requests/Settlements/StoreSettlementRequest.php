<?php

namespace App\Http\Requests\Settlements;

use Illuminate\Foundation\Http\FormRequest;

/**
 * إنشاء تسوية مالية (Phase 4.6 / ADR-041).
 */
class StoreSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:settlements.manage على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delivery_provider_id' => ['nullable', 'exists:delivery_providers,id'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'reported_cod_total' => ['nullable', 'numeric'],
            'reported_fees_total' => ['nullable', 'numeric'],
            'reported_deductions_total' => ['nullable', 'numeric'],
            'reported_net' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
