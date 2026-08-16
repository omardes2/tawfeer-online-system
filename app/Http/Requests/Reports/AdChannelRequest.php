<?php

namespace App\Http\Requests\Reports;

use App\Modules\Marketing\Models\AdChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.ad_budget.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('adChannel')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['required', Rule::in(array_keys(AdChannel::PLATFORMS))],
            // فريد: حسابُ بزنسٍ واحد لا يخدم صفحتين، وإلّا نُسبت مبيعات صفحة لأخرى.
            'delivery_business_id' => [
                'nullable', 'integer', 'exists:delivery_businesses,id',
                Rule::unique('ad_channels', 'delivery_business_id')->ignore($id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('اسم الصفحة'),
            'platform' => __('المنصّة'),
            'delivery_business_id' => __('حساب البزنس'),
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_business_id.unique' => __('هذا الحساب مرتبط بصفحة أخرى — لكل صفحة حساب مستقلّ.'),
        ];
    }
}
