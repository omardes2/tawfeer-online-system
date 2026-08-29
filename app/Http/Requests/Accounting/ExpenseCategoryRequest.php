<?php

namespace App\Http\Requests\Accounting;

use App\Modules\Accounting\Models\ExpenseCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * تحقّق تصنيف المصروف.
 *
 * الاسم فريد بين التصنيفات الحيّة: تصنيفان بالاسم نفسه يفتحان حسابين
 * مختلفين، فينقسم المصروف الواحد على رقمين لا يجتمعان في تقرير.
 */
class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية تُفحص في المتحكّم.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('category')?->id;

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')->whereNull('deleted_at')->ignore($id),
            ],
            'name_en' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
            // مصدرٌ من قائمةٍ مغلقة لا نصٌّ حرّ: التقرير يفرز به، ومصدرٌ لا يعرفه
            // يُخرج التصنيف من الإجمالي بلا أن يُحتسب من مكانٍ آخر — فيضيع المبلغ.
            'auto_source' => ['nullable', 'string', Rule::in(array_keys(ExpenseCategory::AUTO_SOURCES))],
        ];
    }

    /**
     * خطأُ التحقّق يعود JSON للنافذة السريعة.
     *
     * التطبيق يُصيّر JSON لمسارات `api/*` وحدها (bootstrap/app.php)، فلولا هذا
     * لعاد للنافذة تحويلٌ (302) بدل السبب — فيرى المستخدم «تعذّر إنشاء التصنيف»
     * بلا أن يعرف أن الاسم مكرّر.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->wantsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => collect($validator->errors()->all())->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('اسم التصنيف'),
            'name_en' => __('الاسم بالإنجليزية'),
            'sort_order' => __('الترتيب'),
            'auto_source' => __('مصدر الاحتساب الآلي'),
        ];
    }
}
