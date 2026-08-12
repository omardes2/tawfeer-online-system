<?php

namespace App\Http\Requests\Commissions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * قاعدة عمولة/أرباح (Phase 4.2). النطاق يحدّد الأولويّة الحتمية (تُحسَب عند الحفظ).
 */
class CommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:commissions.rules.manage على المسار.
    }

    /**
     * الواجهة تُدخل النسبة مئويةً (3 = 3%) لأنها أوضح للمستخدم، والتخزين كسري (0.03).
     * يبقى تمرير `rate` الكسري مباشرةً مدعومًا (الـAPI والاختبارات القديمة).
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('rate_percent') && ! $this->filled('rate')) {
            $this->merge(['rate' => round((float) $this->input('rate_percent') / 100, 6)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'earner_type' => ['required', Rule::in(['sales', 'affiliate'])],
            'method' => ['required', Rule::in(['percent', 'fixed', 'margin'])],
            'rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // النسبة تلزم لطريقة «نسبة» فقط: «هامش الربح» يمنح الهامش كاملًا، و«مبلغ ثابت» له حقله.
            'rate' => ['nullable', 'numeric', 'min:0', 'max:1', 'required_if:method,percent'],
            'amount' => ['nullable', 'numeric', 'min:0', 'required_if:method,fixed'],
            'user_id' => ['nullable', 'exists:users,id'],
            'campaign' => ['nullable', 'string', 'max:60'],
            'product_id' => ['nullable', 'exists:products,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'role' => ['nullable', 'string', 'max:40'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'include_shipping' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
