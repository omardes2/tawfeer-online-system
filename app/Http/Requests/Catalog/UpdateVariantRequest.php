<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مُفوّض عبر Policy في المتحكم
    }

    public function rules(): array
    {
        return [
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'promo_price' => ['nullable', 'numeric', 'min:0', 'lte:retail_price'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
