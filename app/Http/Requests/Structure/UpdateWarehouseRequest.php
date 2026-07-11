<?php

namespace App\Http\Requests\Structure;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:settings.warehouses.update
    }

    public function rules(): array
    {
        $warehouse = $this->route('warehouse');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => [
                'sometimes', 'required', 'string', 'max:30',
                Rule::unique('warehouses', 'code')
                    ->where('branch_id', $warehouse->branch_id)
                    ->whereNull('deleted_at')
                    ->ignore($warehouse->id),
            ],
            'type' => ['sometimes', Rule::in(['main', 'transit', 'virtual', 'damaged'])],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'allow_negative' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
