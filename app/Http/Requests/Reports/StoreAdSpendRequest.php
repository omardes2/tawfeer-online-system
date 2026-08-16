<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdSpendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.ad_budget.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'spend_date' => ['required', 'date'],
            'ad_channel_id' => ['required', 'integer', 'exists:ad_channels,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'amount_usd' => ['required', 'numeric', 'min:0', 'max:1000000'],
            // سعر صرف يوم الصرف — يُخزَّن مع الصفّ فلا يتحرّك ربح الأمس بتحرّكه.
            'fx_rate' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'conversations' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'spend_date' => __('تاريخ الصرف'),
            'ad_channel_id' => __('القناة'),
            'product_id' => __('الصنف'),
            'amount_usd' => __('قيمة الإعلان'),
            'fx_rate' => __('سعر الصرف'),
            'conversations' => __('عدد المحادثات'),
        ];
    }
}
