<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * تحقّق تحديث مستخدم/موظّف (Production). كلمة المرور اختيارية عند التحديث.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.users.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($userId)],
            'department' => ['nullable', 'string', 'max:100'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'delivery_business_id' => ['nullable', 'integer', 'exists:delivery_businesses,id'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', Rule::in(Role::pluck('name')->all())],
        ];
    }
}
