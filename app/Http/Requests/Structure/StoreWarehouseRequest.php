<?php

namespace App\Http\Requests\Structure;

use App\Modules\Foundation\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية عبر middleware can:settings.warehouses.create
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('branch')) {
            $this->merge([
                'branch_id' => Branch::where('uuid', $this->input('branch'))->value('id'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'branch' => ['required', 'string', 'exists:branches,uuid'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('warehouses', 'code')
                    ->where('branch_id', $this->input('branch_id'))
                    ->whereNull('deleted_at'),
            ],
            'type' => ['nullable', Rule::in(['main', 'transit', 'virtual', 'damaged'])],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'allow_negative' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function payload(): array
    {
        return array_merge(
            $this->safe()->except(['branch']),
            ['branch_id' => $this->input('branch_id')],
        );
    }
}
