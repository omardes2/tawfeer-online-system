<?php

namespace App\Http\Requests\Returns;

use App\Modules\Returns\Support\ReturnWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * إنشاء طلب مرتجع/استبدال (Phase 4.4 / ADR-040).
 */
class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:returns.create على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order' => ['required', 'exists:orders,uuid'],
            'type' => ['required', Rule::in(ReturnWorkflow::TYPES)],
            'reason_code' => ['required', Rule::in(ReturnWorkflow::REASONS)],
            'resolution' => ['required', Rule::in(ReturnWorkflow::RESOLUTIONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.reason_code' => ['nullable', Rule::in(ReturnWorkflow::REASONS)],
            'items.*.exchange_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.exchange_qty' => ['nullable', 'numeric', 'gt:0'],
            'items.*.price_difference' => ['nullable', 'numeric'],
        ];
    }
}
