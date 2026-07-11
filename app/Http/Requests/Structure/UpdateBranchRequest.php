<?php

namespace App\Http\Requests\Structure;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:settings.branches.update
    }

    public function rules(): array
    {
        $branch = $this->route('branch');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('branches', 'code')->ignore($branch->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'timezone' => ['nullable', 'string', 'max:40'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
