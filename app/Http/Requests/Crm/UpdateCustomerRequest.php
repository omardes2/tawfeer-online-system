<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'primary_phone' => ['sometimes', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'category' => ['nullable', 'string', 'max:30'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:40'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_high_risk' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'phones' => ['sometimes', 'array'],
            'phones.*.phone' => ['required_with:phones', 'string', 'max:40'],
            'phones.*.label' => ['nullable', 'string', 'max:40'],
            'phones.*.is_primary' => ['nullable', 'boolean'],
            'addresses' => ['sometimes', 'array'],
            'addresses.*.label' => ['nullable', 'string', 'max:40'],
            'addresses.*.recipient_name' => ['nullable', 'string', 'max:180'],
            'addresses.*.phone' => ['nullable', 'string', 'max:40'],
            'addresses.*.governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
            'addresses.*.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'addresses.*.area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'addresses.*.address_line' => ['nullable', 'string', 'max:500'],
            'addresses.*.is_default' => ['nullable', 'boolean'],
            'contacts' => ['sometimes', 'array'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:180'],
            'contacts.*.position' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:180'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
        ];
    }
}
