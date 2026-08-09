<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class GenerateVariantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مُفوّض عبر Policy في المتحكم
    }

    public function rules(): array
    {
        return [
            'value_ids' => ['required', 'array', 'min:1'],
            'value_ids.*' => ['integer', 'exists:product_attribute_values,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'value_ids.required' => __('اختر قيمة واحدة على الأقل لتوليد المتغيّرات.'),
            'value_ids.min' => __('اختر قيمة واحدة على الأقل لتوليد المتغيّرات.'),
        ];
    }
}
