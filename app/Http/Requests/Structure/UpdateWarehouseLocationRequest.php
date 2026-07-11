<?php

namespace App\Http\Requests\Structure;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:settings.warehouse_locations.update
    }

    public function rules(): array
    {
        $warehouse = $this->route('warehouse');
        $location = $this->route('location');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:40',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $warehouse->id)
                    ->ignore($location->id),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(['zone', 'rack', 'shelf', 'bin'])],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouse->id),
                Rule::notIn([$location->id]), // لا يكون أبًا لنفسه
            ],
            'is_active' => ['boolean'],
        ];
    }
}
