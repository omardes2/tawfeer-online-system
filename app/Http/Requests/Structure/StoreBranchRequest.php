<?php

namespace App\Http\Requests\Structure;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:settings.branches.create
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:branches,code'],
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
