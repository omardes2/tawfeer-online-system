<?php

namespace App\Http\Requests\Sales;

use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/**
 * مبيعات مباشرة (بيع من المستودع بلا توصيل خارجي): بيانات مبسّطة — لا مدينة/منطقة/عنوان.
 */
class StoreDirectSaleRequest extends FormRequest
{
    /**
     * الصلاحية تُفحص هنا لا في المتحكّم وحده: `authorize()` يسبق التحقّق، فيُردّ
     * غيرُ المخوَّل قبل أن تُنفَّذ قواعد `exists:` واستعلاماتها على قاعدة البيانات.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('createDirect', Order::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'warehouse' => ['nullable', 'string', 'exists:warehouses,uuid'],
            // إمّا عميل مسجّل (uuid) أو كتابة اسم عميل جديد.
            'customer' => ['nullable', 'string', 'exists:customers,uuid'],
            'customer_name' => ['required_without:customer', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'distinct', 'exists:product_variants,uuid'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            // كالطلب العادي: الصنف بلا سعر يُسقط الترحيل المحاسبي لاحقًا.
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // الحقل المخفي «customer» قد يصل كسلسلة فارغة في وضع «عميل جديد» — نُطبّعها إلى null.
        if ($this->input('customer') === '') {
            $this->merge(['customer' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'customer_name.required_without' => __('اكتب اسم العميل أو اختر عميلًا مسجّلًا.'),
            'items.required' => __('أضف صنفًا واحدًا على الأقل.'),
            'items.min' => __('أضف صنفًا واحدًا على الأقل.'),
            'items.*.variant.required' => __('اختر الصنف.'),
            'items.*.qty.gt' => __('الكمية يجب أن تكون أكبر من صفر.'),
        ];
    }
}
