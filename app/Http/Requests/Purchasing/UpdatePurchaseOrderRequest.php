<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier' => ['sometimes', 'string', 'exists:suppliers,uuid'],
            'warehouse' => ['sometimes', 'string', 'exists:warehouses,uuid'],
            'order_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.variant' => ['required_with:items', 'string', 'exists:product_variants,uuid'],
            'items.*.qty_ordered' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
