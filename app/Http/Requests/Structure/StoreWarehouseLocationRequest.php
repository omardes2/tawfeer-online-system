<?php

namespace App\Http\Requests\Structure;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:settings.warehouse_locations.create
    }

    public function rules(): array
    {
        $warehouse = $this->route('warehouse');

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $warehouse->id),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['zone', 'rack', 'shelf', 'bin'])],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouse->id),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
