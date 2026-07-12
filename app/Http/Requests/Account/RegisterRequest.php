<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::defaults()],
            // تاريخ الميلاد مطلوب (جاهزية مكافآت الميلاد/التسويق مستقبلًا — ADR-035).
            'birth_date' => ['required', 'date', 'before:today'],
            // قبول الشروط والخصوصية مطلوب (ADR-036).
            'terms' => ['accepted'],
        ];
    }
}
