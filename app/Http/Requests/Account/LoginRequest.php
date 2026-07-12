<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // الحقل يقبل البريد أو رقم الجوال (Phase 3.5).
        return [
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /** بيانات الاعتماد: يحلّ الحقل إلى email أو phone حسب صيغته. */
    public function credentials(): array
    {
        $login = (string) $this->input('email');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        return [$field => $login, 'password' => $this->input('password')];
    }
}
