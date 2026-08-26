<?php

namespace App\Http\Requests\Hr;

use App\Modules\Hr\Models\EmployeeLeave;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تسجيل إجازة. */
class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hr.employees.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(EmployeeLeave::KINDS)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            // الأيام مُدخَلة لا محسوبة: العطل الرسمية ونصف اليوم تجعل الفرق
            // التقويميّ خاطئًا، والمُدخِل يعرف العدد الفعليّ.
            'days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
