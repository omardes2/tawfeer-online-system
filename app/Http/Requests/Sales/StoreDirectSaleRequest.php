<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * مبيعات مباشرة (بيع من المستودع بلا توصيل خارجي): بيانات مبسّطة — لا مدينة/منطقة/عنوان.
 */
class StoreDirectSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse' => ['nullable', 'string', 'exists:warehouses,uuid'],
            'customer_name' => ['required', 'string', 'max:180'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'distinct', 'exists:product_variants,uuid'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_name.required' => __('اسم العميل مطلوب.'),
            'items.required' => __('أضف صنفًا واحدًا على الأقل.'),
            'items.min' => __('أضف صنفًا واحدًا على الأقل.'),
            'items.*.variant.required' => __('اختر الصنف.'),
            'items.*.qty.gt' => __('الكمية يجب أن تكون أكبر من صفر.'),
        ];
    }
}
