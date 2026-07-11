<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'code' => ['required', 'string', 'max:50', 'unique:suppliers,code'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'contacts' => ['sometimes', 'array'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:180'],
            'contacts.*.position' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:180'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'contacts.*.notes' => ['nullable', 'string'],
        ];
    }
}
