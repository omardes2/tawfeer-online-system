<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * هوية المتجر (Phase 3.2): تدعم المستخدم المُصادَق (Sanctum) والضيف (رمز سلة في الترويسة).
 * ترفض بـ401 إن غابت الهوية — يحافظ على سلوك 3.1 لغير المُصادَق دون رمز.
 */
class ResolveStoreIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        // مستخدم مُصادَق (actingAs في الاختبارات، أو Bearer عبر حارس sanctum).
        $user = $request->user() ?? $request->user('sanctum');
        if ($user) {
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);

            return $next($request);
        }

        // ضيف: رمز سلة (UUID) صالح في الترويسة X-Cart-Token.
        $token = $request->header('X-Cart-Token');
        if (is_string($token) && Str::isUuid($token)) {
            return $next($request);
        }

        abort(401);
    }
}
