<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierReturnRequest extends FormRequest
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
            'purchase_order' => ['nullable', 'string', 'exists:purchase_orders,uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'exists:product_variants,uuid'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
