<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * مزامنة مصفوفة متغيّرات المنتج (سعر/كمية لكل تركيبة قيم). قد تكون فارغة (حذف كل المتغيّرات).
 */
class SyncVariantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مُفوّض عبر Policy في المتحكم
    }

    public function rules(): array
    {
        return [
            'combos' => ['nullable', 'array'],
            'combos.*.values' => ['required', 'array', 'min:1'],
            'combos.*.values.*' => ['integer', 'exists:product_attribute_values,id'],
            'combos.*.price' => ['nullable', 'numeric', 'min:0'],
            'combos.*.stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
