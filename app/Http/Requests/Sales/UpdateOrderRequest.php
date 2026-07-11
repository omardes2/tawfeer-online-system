<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse' => ['sometimes', 'string', 'exists:warehouses,uuid'],
            'customer_name' => ['sometimes', 'string', 'max:180'],
            'customer_phone' => ['sometimes', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'channel' => ['sometimes', Rule::in(['web', 'manual', 'marketer', 'pos'])],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.variant' => ['required_with:items', 'string', 'distinct', 'exists:product_variants,uuid'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
