<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $attribute = $this->route('attribute');
        $value = $this->route('value');

        return [
            'value' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('product_attribute_values', 'value')->where('attribute_id', $attribute->id)->ignore($value->id),
            ],
            'label' => ['nullable', 'string', 'max:120'],
            'color_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
