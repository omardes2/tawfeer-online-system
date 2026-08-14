<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تقييم منتج من زبون.
 *
 * التحقّق من **حقّ** الكتابة (شراء مستلَم، وبلا تكرار) يقع في المتحكّم لأنه
 * يحتاج المنتج والزبون معًا؛ هنا شكل المدخلات وحدها.
 */
class StoreProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            // حدّ أعلى يمنع إغراق الصفحة، وأدنى يمنع «..» رأيًا.
            'body' => ['nullable', 'string', 'min:3', 'max:1500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'rating' => __('storefront.rating'),
            'title' => __('storefront.review_title'),
            'body' => __('storefront.review_body'),
        ];
    }
}
