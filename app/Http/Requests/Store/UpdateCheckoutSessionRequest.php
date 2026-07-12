<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تحديث بيانات جلسة الإتمام تدريجيًا (كل الحقول اختيارية، تُتحقّق إن حضرت).
 */
class UpdateCheckoutSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الملكية تُفحص في المتحكّم (هوية المتجر).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'shipping_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'payment_method_code' => ['sometimes', 'nullable', 'string', 'exists:payment_methods,code'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
