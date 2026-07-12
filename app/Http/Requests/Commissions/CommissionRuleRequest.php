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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'earner_type' => ['required', Rule::in(['sales', 'affiliate'])],
            'method' => ['required', Rule::in(['percent', 'fixed', 'margin'])],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:1', 'required_unless:method,fixed'],
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
