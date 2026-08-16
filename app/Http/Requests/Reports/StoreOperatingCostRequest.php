<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class StoreOperatingCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.ad_budget.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'effective_from' => __('يسري من'),
            'amount' => __('المصروف اليومي'),
            'note' => __('ملاحظة'),
        ];
    }
}
