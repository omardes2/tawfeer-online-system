<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تحقّق إنشاء/تعديل خزينة أو حساب بنكي (Phase 7.1).
 */
class StoreTreasuryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية تُفحص في المتحكّم حسب النوع.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $treasuryId = $this->route('treasury')?->id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('treasuries', 'code')->ignore($treasuryId)],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'size:3'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'gl_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['nullable', 'boolean'],
            // حقول بنكية (اختيارية).
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:40'],
            'swift' => ['nullable', 'string', 'max:20'],
        ];
    }
}
