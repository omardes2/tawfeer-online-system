<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order' => ['required', 'string', 'exists:purchase_orders,uuid'],
            'additional_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'exists:product_variants,uuid'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.qty_received' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
