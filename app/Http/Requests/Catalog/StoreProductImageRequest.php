<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'alt' => ['nullable', 'string', 'max:200'],
            'is_primary' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
