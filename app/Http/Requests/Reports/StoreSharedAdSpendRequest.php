<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * إعلانٌ بميزانيةٍ واحدة لعدّة أصناف.
 *
 * صنفان على الأقلّ: إعلانٌ بصنفٍ واحد ليس مشتركًا، ومكانُه صفّ الصنف نفسه —
 * وإدخاله هنا يُنشئ صرفًا موزَّعًا لا يقبله حقلُ الصفّ بالتعديل.
 */
class StoreSharedAdSpendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.ad_budget.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'spend_date' => ['required', 'date'],
            'ad_channel_id' => ['required', 'integer', 'exists:ad_channels,id'],
            'label' => ['required', 'string', 'max:120'],
            'product_ids' => ['required', 'array', 'min:2'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'amount_usd' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'fx_rate' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'conversations' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'spend_date' => __('تاريخ الصرف'),
            'ad_channel_id' => __('القناة'),
            'label' => __('اسم الإعلان'),
            'product_ids' => __('الأصناف'),
            'amount_usd' => __('قيمة الإعلان'),
            'fx_rate' => __('سعر الصرف'),
            'conversations' => __('عدد المحادثات'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_ids.min' => __('الإعلان المشترك صنفان فأكثر — أمّا صنفٌ واحد فمكانه صفّه في الجدول.'),
        ];
    }
}
