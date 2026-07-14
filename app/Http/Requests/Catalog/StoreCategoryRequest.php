<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مُفوّض عبر Policy في المتحكم
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', 'unique:categories,slug'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'], // أيقونة الفئة (≤1MB)
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
            // حسابات محاسبية اختيارية للفئة (تجاوز إعدادات الترحيل الافتراضية).
            'revenue_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'inventory_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'cogs_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ];
    }
}
