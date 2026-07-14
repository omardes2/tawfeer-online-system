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
        // على شاشة الإدارة تكون بيانات التوصيل إجبارية (للإرسال لأوبتيموس)؛ الـAPI يبقى مرِنًا.
        $admin = $this->routeIs('admin.sales.orders.store');

        return [
            // المستودع اختياري (مستودع واحد افتراضي — يُحلّ في المتحكّم إن غاب).
            'warehouse' => ['nullable', 'string', 'exists:warehouses,uuid'],
            'customer' => ['nullable', 'string', 'exists:customers,uuid'], // ربط اختياري بعميل CRM
            'customer_name' => [$admin ? 'required' : 'required_without:customer', 'string', 'max:180'],
            'customer_phone' => [$admin ? 'required' : 'required_without:customer', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            // مدينة/منطقة التوصيل المُعيَّنة (نمط Opost — بالمعرّف لا الاسم).
            'city_id' => [$admin ? 'required' : 'nullable', 'integer', 'exists:cities,id'],
            'area_id' => [$admin ? 'required' : 'nullable', 'integer', 'exists:areas,id'],
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

    /** رسائل خطأ عربية واضحة لكل حقل مفقود. */
    public function messages(): array
    {
        return [
            'customer_name.required' => __('اسم العميل مطلوب.'),
            'customer_name.required_without' => __('اسم العميل مطلوب.'),
            'customer_phone.required' => __('رقم الهاتف مطلوب.'),
            'customer_phone.required_without' => __('رقم الهاتف مطلوب.'),
            'city_id.required' => __('المدينة مطلوبة.'),
            'city_id.exists' => __('المدينة المختارة غير صالحة.'),
            'area_id.required' => __('المنطقة مطلوبة.'),
            'area_id.exists' => __('المنطقة المختارة غير صالحة.'),
            'items.required' => __('أضف صنفًا واحدًا على الأقل.'),
            'items.min' => __('أضف صنفًا واحدًا على الأقل.'),
            'items.*.variant.required' => __('اختر الصنف.'),
            'items.*.qty.required' => __('الكمية مطلوبة.'),
            'items.*.qty.gt' => __('الكمية يجب أن تكون أكبر من صفر.'),
        ];
    }

    /** أسماء الحقول بالعربية في رسائل التحقّق. */
    public function attributes(): array
    {
        return [
            'customer_name' => __('اسم العميل'),
            'customer_phone' => __('رقم الهاتف'),
            'city_id' => __('المدينة'),
            'area_id' => __('المنطقة'),
            'shipping_address' => __('العنوان'),
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
