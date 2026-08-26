<?php

namespace App\Http\Requests\Hr;

use App\Modules\Hr\Models\EmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** إنشاء ملفّ موظف أو تعديله. */
class EmployeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hr.employees.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profile = $this->route('employee');

        return [
            // مستخدمٌ واحد = ملفٌّ واحد: ملفّان لشخصٍ يجعلان راتبه بندين في المسيّر.
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('employee_profiles', 'user_id')
                    ->ignore($profile?->id)
                    ->whereNull('deleted_at'),
            ],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'hire_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'status' => ['required', Rule::in(['active', 'ended'])],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract'])],
            'annual_leave_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'bank_account' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.unique' => __('لهذا المستخدم ملفّ موظفٍ بالفعل.'),
            'end_date.after_or_equal' => __('تاريخ انتهاء الخدمة قبل تاريخ التعيين.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        // من انتهت خدمته يُغلق ملفّه: تركُه «نشطًا» يُبقيه في مسيّرات الأشهر
        // القادمة، ويتراكم له مخصّصُ نهاية خدمةٍ انتهت.
        if ($this->filled('end_date')) {
            $this->merge(['status' => 'ended']);
        }
    }

    public function profileData(): array
    {
        return array_merge($this->validated(), [
            'created_by' => $this->route('employee') instanceof EmployeeProfile ? null : $this->user()->id,
        ]);
    }
}
