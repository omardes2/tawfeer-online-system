<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * الرصيد الافتتاحي يُنشئ قيدًا في اليومية، فيُسقَط ممّن لا يملك صلاحية
     * القيود. وإسقاطُه يعني «لا تمسّه» لا «صفّره» — فحفظُ موظّفٍ لبيانات المورد
     * لا يمحو رصيدًا مُرحّلًا.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->user()?->can('accounting.journal.create')) {
            $this->request->remove('opening_balance');
        }
    }

    public function rules(): array
    {
        $supplier = $this->route('supplier');

        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'contacts' => ['sometimes', 'array'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:180'],
            'contacts.*.position' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:180'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'contacts.*.notes' => ['nullable', 'string'],
        ];
    }
}
