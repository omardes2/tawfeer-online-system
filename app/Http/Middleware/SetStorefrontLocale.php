<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * لغة المتجر (Phase 3.3): عربي أساسي (RTL) مع الإنجليزية. تُقرأ من الجلسة
 * (يضبطها مبدّل اللغة)، والافتراضي من الإعداد. تُطبَّق على مسارات المتجر فقط.
 */
class SetStorefrontLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locales = (array) config('storefront.locales', ['ar', 'en']);
        $locale = $request->session()->get('storefront_locale', config('storefront.default_locale', 'ar'));

        if (in_array($locale, $locales, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
