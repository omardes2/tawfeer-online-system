<?php

namespace App\Http\Requests\Commissions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * صرف دفعة عمولات معتمدة لمستفيد واحد (Phase 4.2).
 */
class PayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:commissions.payout على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'earner_id' => ['required', 'exists:users,id'],
            'earner_type' => ['required', Rule::in(['sales', 'affiliate'])],
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => ['integer'],
            'reference' => ['nullable', 'string', 'max:80'],
        ];
    }
}
