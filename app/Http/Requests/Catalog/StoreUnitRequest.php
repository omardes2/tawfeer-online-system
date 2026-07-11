<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'name_en' => ['nullable', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:20', 'unique:units,code'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'base_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'conversion_factor' => ['numeric', 'gt:0'],
            'is_active' => ['boolean'],
        ];
    }
}
