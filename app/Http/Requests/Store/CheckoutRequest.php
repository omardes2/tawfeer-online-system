<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // إتمام الشراء ذاتي النطاق بالمستخدم المُصادَق (لا صلاحية خاصة — ملكية ذاتية).
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:180'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            'shipping_address' => ['required', 'string', 'max:1000'],
            'payment_method' => ['required', 'string', 'exists:payment_methods,code'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
