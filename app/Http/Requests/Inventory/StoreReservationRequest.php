<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
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
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
