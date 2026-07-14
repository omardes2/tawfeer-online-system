<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // المستودع اختياري (مستودع واحد افتراضي — يُحلّ في المتحكّم إن غاب).
            'warehouse' => ['nullable', 'string', 'exists:warehouses,uuid'],
            'customer' => ['nullable', 'string', 'exists:customers,uuid'], // ربط اختياري بعميل CRM
            'customer_name' => ['required_without:customer', 'string', 'max:180'],
            'customer_phone' => ['required_without:customer', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            // مدينة/منطقة التوصيل المُعيَّنة (نمط Opost — بالمعرّف لا الاسم).
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'has_return' => ['sometimes', 'boolean'],
            'return_notes' => ['nullable', 'string', 'max:1000'],
            'channel' => ['sometimes', Rule::in(['web', 'manual', 'marketer', 'pos'])],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'distinct', 'exists:product_variants,uuid'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // خانة التبديل ترسل "on"/"1"/غياب — نُطبّعها إلى boolean.
        $this->merge([
            'has_return' => $this->boolean('has_return'),
        ]);
    }
}
