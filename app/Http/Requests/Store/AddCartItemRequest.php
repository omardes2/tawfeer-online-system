<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant' => ['required', 'string', 'exists:product_variants,uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
