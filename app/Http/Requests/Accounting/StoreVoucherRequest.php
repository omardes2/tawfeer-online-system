<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تحقّق إنشاء/تعديل سند مالي (Phase 7.1) — قبض/صرف/مصروف/إيراد آخر.
 * (التحويلات لها طلبها الخاص.)
 */
class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية تُفحص في المتحكّم حسب النوع.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->counterRules(), [
            'voucher_date' => ['required', 'date'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'party_name' => ['nullable', 'string', 'max:150'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_recurring' => ['nullable', 'boolean'],
            'attachments.*' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
        ]);
    }

    /**
     * الطرف المقابل: حسابٌ في كل الأنواع، وتصنيفٌ في المصروف.
     *
     * سند المصروف يختار **تصنيفًا** والمتحكّم يشتقّ حسابه — فلا يُطلب من
     * المستخدم معرفةُ الدليل. وعند التعديل يبقى التصنيف اختياريًا: سنداتٌ قديمة
     * أُنشئت على حسابٍ مباشر بلا تصنيف، وإلزامُه كان سيمنع تعديل بيانها أو
     * مرفقها ما لم يُغيَّر حسابها معه.
     *
     * @return array<string, mixed>
     */
    private function counterRules(): array
    {
        if ($this->route('kind') !== 'expense') {
            return ['counter_account_id' => ['required', 'integer', 'exists:accounts,id']];
        }

        return [
            'counter_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'expense_category_id' => [
                $this->isMethod('POST') ? 'required' : 'nullable',
                'integer', 'exists:expense_categories,id',
            ],
        ];
    }
}
