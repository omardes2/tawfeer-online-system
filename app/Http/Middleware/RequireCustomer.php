<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حماية صفحات حساب المتجر (Phase 3.4): تتطلّب جلسة مصادَقة، وتوجّه غير المُصادَق
 * إلى دخول المتجر (لا لوحة الإدارة). منفصلة عن مصادقة الإدارة.
 */
class RequireCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->guest(route('account.login'));
        }

        return $next($request);
    }
}
