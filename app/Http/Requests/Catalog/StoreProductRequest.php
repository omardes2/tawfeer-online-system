<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // مُفوّض عبر Policy في المتحكم
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],

            'name' => ['required', 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', 'unique:products,slug'],
            // اختياري: يُولَّد تلقائيًا (P-XXXXXXXX) عند غيابه — أُزيل حقل SKU من النموذج.
            'sku' => ['nullable', 'string', 'max:60', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'type' => ['sometimes', Rule::in(['simple', 'variable'])],

            'short_description' => ['nullable', 'string', 'max:500'],
            'short_description_en' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],

            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'visibility' => ['sometimes', Rule::in(['visible', 'hidden'])],
            'is_featured' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'track_inventory' => ['boolean'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            // حجم الوحدة بالمتر المكعّب — أساس توزيع الشحن البحري في فواتير الاستيراد.
            'cbm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            // حدّ التنبيه بالنقص؛ فارغًا يعني الرجوع للحدّ الافتراضي في الإعدادات.
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            // الأسعار (تُزامَن مع المتغيّر الافتراضي — تظهر في الموقع والطلبات).
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'promo_price' => ['nullable', 'numeric', 'min:0', 'lte:retail_price'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],

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
