<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.shipments.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:80'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'shipped_at' => ['nullable', 'date'],
            'arrived_at' => ['nullable', 'date', 'after_or_equal:shipped_at'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'arrived_at.after_or_equal' => __('تاريخ الوصول لا يسبق تاريخ الشحن.'),
        ];
    }
}
