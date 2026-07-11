<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant' => ['required', 'string', 'exists:product_variants,uuid'],
            'warehouse' => ['required', 'string', 'exists:warehouses,uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string'],
        ];
    }
}
