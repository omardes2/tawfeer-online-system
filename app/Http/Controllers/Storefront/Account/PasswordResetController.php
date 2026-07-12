<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * استرجاع كلمة المرور لحساب المتجر (Phase 3.5): واجهات المتجر RTL مع إعادة استخدام
 * وسيط كلمات المرور القياسي (broker). لا منطق أعمال مكرّر.
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('storefront.account.auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        return back()->with('status', __($status));
    }

    public function reset(Request $request, string $token): View
    {
        return view('storefront.account.auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            fn ($user, $password) => $user->forceFill(['password' => Hash::make($password)])->save(),
        );

        return $status === PasswordBroker::PASSWORD_RESET
            ? redirect()->route('account.login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
