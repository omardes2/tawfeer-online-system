<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unit = $this->route('unit');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'name_en' => ['nullable', 'string', 'max:60'],
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('units', 'code')->ignore($unit->id)],
            'symbol' => ['nullable', 'string', 'max:20'],
            'base_unit_id' => ['nullable', 'integer', 'exists:units,id', Rule::notIn([$unit->id])],
            'conversion_factor' => ['numeric', 'gt:0'],
            'is_active' => ['boolean'],
        ];
    }
}
