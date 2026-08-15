<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تعديل كميات صنف من كرت الصنف.
 *
 * الكميات تصل كخريطة `quantities[variant_id] = الكمية الجديدة`. الخانة الفارغة
 * تعني «لا تغيير» فتُتجاهل — لا تعني صفرًا.
 */
class UpdateProductQuantitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // تعديل المخزون صلاحية مخزنية لا صلاحية تحرير منتج: مَن يصحّح اسم صنف
        // ليس بالضرورة مَن يملك تغيير أرصدته.
        return $this->user()?->can('inventory.operations.receive')
            && $this->user()?->can('inventory.operations.issue');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            // السبب يُحفظ على الحركة — «لماذا تغيّر الرصيد؟» سؤالٌ يُسأل لاحقًا.
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantities.*.min' => __('الكمية لا تقبل قيمة سالبة.'),
            'warehouse_id.required' => __('اختر المستودع.'),
        ];
    }
}
