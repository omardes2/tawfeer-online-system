<?php

namespace App\Http\Requests\Sales;

use App\Modules\Store\Models\CheckoutSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AbandonedCheckoutOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.abandoned_checkouts.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'recovery_status' => ['required', Rule::in(CheckoutSession::RECOVERY_STATUSES)],
            'recovery_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'recovery_status' => __('نتيجة الاتصال'),
            'recovery_note' => __('الملاحظة'),
        ];
    }
}
