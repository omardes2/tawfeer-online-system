<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'exists:suppliers,uuid'],
            'warehouse' => ['required', 'string', 'exists:warehouses,uuid'],
            'order_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'exists:product_variants,uuid'],
            'items.*.qty_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
