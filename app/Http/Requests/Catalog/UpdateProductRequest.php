<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'unit_id' => ['sometimes', 'required', 'integer', 'exists:units,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],

            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', Rule::unique('products', 'slug')->ignore($product->id)],
            'sku' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($product->id)],
            'barcode' => ['nullable', 'string', 'max:60'],
            'type' => ['sometimes', Rule::in(['simple', 'variable'])],

            'short_description' => ['nullable', 'string', 'max:500'],
            'short_description_en' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],

            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'visibility' => ['sometimes', Rule::in(['visible', 'hidden'])],
            'is_featured' => ['boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'track_inventory' => ['boolean'],
            'weight' => ['nullable', 'numeric', 'min:0'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'search_keywords' => ['nullable', 'string'],

            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:product_tags,id'],
            'attribute_ids' => ['nullable', 'array'],
            'attribute_ids.*' => ['integer', 'exists:product_attributes,id'],
        ];
    }
}
