<?php

namespace App\Support\Integrations\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * مُدير توكن Opost (OAuth2 Password Grant، المبدأ 13). Opost يُصدر توكنًا قصير الصلاحية
 * عبر POST /oauth/token، فلا يصلح توكن ثابت في .env (ينتهي دائمًا ⇒ 401). هذا المُدير
 * يسجّل الدخول آليًا بـ client_id/secret + username/password، يخزّن التوكن مع هامش أمان،
 * ويجدّده تلقائيًا عند الانتهاء أو عند 401. كل أسرار OAuth في .env فقط.
 */
class OpostTokenProvider
{
    private const CACHE_KEY = 'integrations:opost:access_token';

    /**
     * التوكن الصالح الحالي (من الكاش أو بتسجيل دخول جديد)، أو null إن تعذّر.
     * $forceRefresh يتجاوز الكاش (يُستخدم عند استقبال 401 لإعادة المصادقة).
     */
    public function token(bool $forceRefresh = false): ?string
    {
        // إن توفّرت بيانات OAuth ⇒ هي المصدر (تجديد تلقائي). وإلا نعود لتوكن ثابت إن وُضع.
        if (! $this->canLogin()) {
            $static = (string) config('services.opost.token');

            return $static !== '' ? $static : null;
        }

        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->login();
    }

    /** إبطال التوكن المخزّن (يُستدعى عند 401 لإجبار تسجيل دخول جديد). */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** هل تتوفّر بيانات OAuth الكاملة لتسجيل الدخول؟ */
    public function canLogin(): bool
    {
        return (string) config('services.opost.client_id') !== ''
            && (string) config('services.opost.username') !== '';
    }

    /** تسجيل دخول OAuth (Password Grant) وتخزين التوكن. */
    private function login(): ?string
    {
        try {
            $res = Http::acceptJson()->asForm()->timeout(30)->post(
                (string) config('services.opost.oauth_url', 'https://opost.ps/oauth/token'),
                array_filter([
                    'grant_type' => 'password',
                    'client_id' => config('services.opost.client_id'),
                    'client_secret' => config('services.opost.client_secret'),
                    'username' => config('services.opost.username'),
                    'password' => config('services.opost.password'),
                    'scope' => (string) config('services.opost.scope', ''),
                ], fn ($v) => $v !== null),
            );

            if (! $res->successful()) {
                Log::warning('Opost OAuth login failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            $token = $res->json('access_token');
            if (! is_string($token) || $token === '') {
                Log::warning('Opost OAuth login returned no access_token', ['body' => $res->body()]);

                return null;
            }

            // هامش أمان 60 ثانية قبل انتهاء الصلاحية.
            $ttl = max(60, (int) ($res->json('expires_in') ?? 3600) - 60);
            Cache::put(self::CACHE_KEY, $token, $ttl);

            return $token;
        } catch (\Throwable $e) {
            Log::warning('Opost OAuth login error: '.$e->getMessage());

            return null;
        }
    }
}
