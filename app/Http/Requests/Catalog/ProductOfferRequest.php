<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * إدخال عرض كمّية — التحقّق هنا لا في المتحكّم (اصطلاح المشروع).
 */
class ProductOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.products.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $offer = $this->route('offer');

        return [
            /*
            | من قطعتين فصاعدًا: «عرضٌ» على قطعةٍ واحدة ليس عرضًا بل سعرٌ ثانٍ
            | للصنف، ومكانُه حقل السعر الترويجي لا هنا. والفريد يمنع عرضين
            | بالكمّية نفسها — وإلّا صار السعر رهنَ ترتيبٍ عشوائي.
            */
            'min_qty' => [
                'required', 'integer', 'min:2', 'max:999',
                Rule::unique('product_offers', 'min_qty')
                    ->where('product_id', $product?->id)
                    ->ignore($offer?->id),
            ],
            'total_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'label' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'min_qty.unique' => __('يوجد عرضٌ بهذه الكمّية على الصنف — عدّله بدل إضافة ثانٍ.'),
            'min_qty.min' => __('العرض يبدأ من قطعتين. لسعرٍ خاصّ للقطعة الواحدة استعمل السعر الترويجي.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
