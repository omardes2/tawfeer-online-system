<?php

namespace App\Http\Requests\Shipping;

use App\Modules\Shipping\Support\DeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * انتقال قانوني يدوي في دورة حياة التوصيل (Phase 4.3 / ADR-038).
 */
class DeliveryTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:shipping.delivery.manage على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', Rule::in(DeliveryStatus::all())],
            'reason' => ['nullable', Rule::in(DeliveryStatus::HOLD_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
