<?php

namespace App\Support\Social;

use App\Support\Contracts\SocialAuthProvider;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * تنفيذ المصادقة الاجتماعية عبر Laravel Socialite (Phase 3.5 / ADR-036).
 * الأسرار من config/services.php ← .env حصريًا. Socialite يتكفّل بتحقّق الحالة (state)
 * وأمان الـcallback. تبديل/إضافة مزوّد = إعداد فقط، دون لمس منطق الهوية.
 */
class SocialiteAuthProvider implements SocialAuthProvider
{
    public function redirect(string $provider): RedirectResponse
    {
        $this->assertSupported($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function userFromCallback(string $provider): SocialUserData
    {
        $this->assertSupported($provider);
        $user = Socialite::driver($provider)->user();

        return new SocialUserData(
            provider: $provider,
            id: (string) $user->getId(),
            email: $user->getEmail(),
            name: $user->getName() ?: $user->getNickname(),
            avatar: $user->getAvatar(),
        );
    }

    private function assertSupported(string $provider): void
    {
        abort_unless(in_array($provider, (array) config('social.providers', []), true), 404);
    }
}
