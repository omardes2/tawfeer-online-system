<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.price_lists.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('priceList')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('price_lists', 'code')->ignore($id)->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'code' => $this->filled('code') ? $this->string('code')->trim()->value() : null,
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('اسم القائمة'),
            'code' => __('الرمز'),
            'parent_id' => __('القائمة الأب'),
            'notes' => __('ملاحظات'),
        ];
    }
}
