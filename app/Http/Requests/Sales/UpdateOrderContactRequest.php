<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تعديل فاتورة/طلب قائم: بيانات التواصل/التوصيل + الأصناف/الكميات/الأسعار. عند الحفظ تُزامَن
 * الحركة المخزونية ويُحدَّث القيد المحاسبي في مكانه. متاح ما لم يُرسَل الطلب لشركة التوصيل بعد.
 */
class UpdateOrderContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:180'],
            // رقم فلسطيني صحيح 10 خانات (يُطبَّع في prepareForValidation) — شرط Opost.
            'customer_phone' => ['required', 'string', 'max:40', 'regex:/^\d{10}$/'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            'shipping_address' => ['required', 'string', 'max:1000'],
            // عدد الطرود لا القطع — يُصحَّح قبل الإرسال، فبعده لا تتزامن التعديلات.
            'parcels_count' => ['sometimes', 'integer', 'min:1', 'max:'.config('shipping.max_parcels_per_shipment', 12)],
            'notes' => ['nullable', 'string', 'max:2000'],
            // الأصناف (تعديل الكميات/الأسعار) — صنف واحد على الأقل.
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'distinct', 'exists:product_variants,uuid'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** رسائل خطأ عربية واضحة لكل حقل. */
    public function messages(): array
    {
        return [
            'customer_name.required' => __('اسم العميل مطلوب.'),
            'customer_phone.required' => __('رقم الهاتف مطلوب.'),
            'customer_phone.regex' => __('رقم الهاتف يجب أن يكون 10 أرقام (مثال: 0599123456).'),
            'shipping_address.required' => __('العنوان مطلوب.'),
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
            'customer_email' => __('البريد الإلكتروني'),
            'shipping_address' => __('العنوان'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('customer_phone')) {
            $this->merge(['customer_phone' => $this->normalizePhone($this->input('customer_phone'))]);
        }
    }

    /** تطبيع الهاتف لصيغة 10 خانات محلّية (إزالة الرموز، تحويل 970/00970، إضافة صفر بادئ). */
    private function normalizePhone(mixed $phone): mixed
    {
        if (! is_string($phone) || $phone === '') {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00970')) {
            $digits = '0'.substr($digits, 5);
        } elseif (str_starts_with($digits, '970')) {
            $digits = '0'.substr($digits, 3);
        }

        if (strlen($digits) === 9 && $digits[0] !== '0') {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
