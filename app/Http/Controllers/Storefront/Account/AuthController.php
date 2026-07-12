<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\LoginRequest;
use App\Http\Requests\Account\RegisterRequest;
use App\Modules\Store\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * مصادقة حساب المتجر (Phase 3.4): تسجيل/دخول/خروج بجلسة الويب. عند الدخول/التسجيل
 * تُدمج سلة الضيف في سلة المستخدم (يُعاد استخدام AccountService/CartService).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AccountService $account) {}

    public function showRegister(): View
    {
        return view('storefront.account.auth.register');
    }

    public function register(RegisterRequest $request)
    {
        $user = $this->account->register($request->validated());
        Auth::login($user);
        $request->session()->regenerate();
        $this->account->mergeGuestCart($user, $request->cookie('cart_token'));

        return redirect()->route('account.dashboard');
    }

    public function showLogin(): View
    {
        return view('storefront.account.auth.login');
    }

    public function login(LoginRequest $request)
    {
        // يقبل البريد أو رقم الجوال (Phase 3.5) عبر credentials().
        if (! Auth::attempt($request->credentials(), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $request->session()->regenerate();
        $this->account->mergeGuestCart(Auth::user(), $request->cookie('cart_token'));

        return redirect()->intended(route('account.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('storefront.home');
    }
}
