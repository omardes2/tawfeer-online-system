<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class TransferStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant' => ['required', 'string', 'exists:product_variants,uuid'],
            'from_warehouse' => ['required', 'string', 'exists:warehouses,uuid'],
            'to_warehouse' => ['required', 'string', 'different:from_warehouse', 'exists:warehouses,uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string'],
        ];
    }
}
