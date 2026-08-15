<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * حذف جماعي لأصناف مُحدَّدة.
 *
 * الصلاحية تُفحص **لكل منتج على حدة** في المتحكّم لا هنا: سياسة الحذف تأخذ
 * النموذج، وقائمة المعرّفات تصل من المتصفّح فلا يجوز الاكتفاء بفحص عامّ.
 */
class BulkDeleteProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // حدّ أعلى: صفحة الجدول عشرون صنفًا، والسقف يمنع طلبًا مُلفَّقًا
            // يمسح الكتالوج كلّه في نداء واحد.
            'products' => ['required', 'array', 'min:1', 'max:200'],
            'products.*' => ['integer', 'distinct', 'exists:products,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'products.required' => __('لم تُحدَّد أي أصناف.'),
            'products.max' => __('لا يمكن حذف أكثر من 200 صنف في مرّة واحدة.'),
        ];
    }
}
