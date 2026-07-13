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
        return [
            'voucher_date' => ['required', 'date'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'counter_account_id' => ['required', 'integer', 'exists:accounts,id'],
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
        ];
    }
}
