<?php

namespace Tests\Support;

use App\Support\Contracts\SocialAuthProvider;
use App\Support\Social\SocialUserData;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * مزوّد مصادقة اجتماعية وهمي للاختبارات (Phase 3.5) — يتيح اختبار منطق الهوية دون
 * OAuth حقيقي. تُضبط بيانات المستخدم عبر ::$user قبل استدعاء الـcallback.
 */
class FakeSocialAuthProvider implements SocialAuthProvider
{
    public static ?SocialUserData $user = null;

    public function redirect(string $provider): SymfonyRedirect
    {
        return new RedirectResponse("/auth/{$provider}/callback");
    }

    public function userFromCallback(string $provider): SocialUserData
    {
        return self::$user ?? new SocialUserData($provider, 'fake-'.$provider);
    }
}
