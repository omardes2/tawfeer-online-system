<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Modules\Store\Services\SocialAuthService;
use App\Support\Contracts\SocialAuthProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * الدخول الاجتماعي (Phase 3.5 / ADR-036): إعادة التوجيه والـcallback عبر طبقة تكامل
 * قابلة للتبديل (Socialite). أمان: تحقّق الحالة/CSRF داخل Socialite، والملكية عبر
 * البريد الموثّق. لا أسرار في المتحكّم. المنطق في SocialAuthService.
 */
class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialAuthService $service) {}

    /** بدء الدخول عبر مزوّد (نيّة: login). */
    public function redirect(SocialAuthProvider $social, Request $request, string $provider): SymfonyRedirect
    {
        $request->session()->put('social_intent', 'login');

        return $social->redirect($provider);
    }

    /** بدء ربط مزوّد بحساب مُسجَّل الدخول (نيّة: link). */
    public function redirectLink(SocialAuthProvider $social, Request $request, string $provider): SymfonyRedirect
    {
        $request->session()->put('social_intent', 'link');

        return $social->redirect($provider);
    }

    public function callback(SocialAuthProvider $social, Request $request, string $provider): RedirectResponse
    {
        try {
            $data = $social->userFromCallback($provider);
        } catch (\Throwable $e) {
            return redirect()->route('account.login')->withErrors(['email' => __('account.social_failed')]);
        }

        $intent = $request->session()->pull('social_intent', 'login');

        // ربط مزوّد بحساب حالي.
        if ($intent === 'link' && auth()->check()) {
            try {
                $this->service->link($request->user(), $data);
            } catch (ValidationException $e) {
                return redirect()->route('account.profile')->withErrors($e->errors());
            }

            return redirect()->route('account.profile')->with('status', __('account.provider_linked'));
        }

        // تسجيل/دخول.
        $result = $this->service->authenticate($data, $request->cookie('cart_token'));
        $request->session()->regenerate();

        return $result['needs_completion']
            ? redirect()->route('account.profile.complete')
            : redirect()->intended(route('account.dashboard'));
    }
}
