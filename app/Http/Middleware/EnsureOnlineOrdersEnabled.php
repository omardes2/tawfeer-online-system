<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بوّابة الطلب أونلاين — من إعدادات قاعدة البيانات (`store.online_orders_enabled`).
 *
 * تُغلق صفحة إتمام الشراء وواجهة جلسة الإتمام معًا. إغلاق الصفحة وحدها لا يكفي:
 * الواجهة تُنادى مباشرة من JavaScript، فيبقى الطلب ممكنًا بطلب HTTP واحد.
 *
 * السلة تبقى تعمل عمدًا: الزبون يجمع ما يريد، ويُبلَّغ عند الإتمام أن الطلب
 * أونلاين متوقّف مؤقتًا مع بديل للتواصل — بدل أن تختفي السلة بلا تفسير.
 *
 * محصَّن: أي خطأ في قراءة الإعداد يعني «مفعّل» (فتح آمن) كي لا يوقف عطلٌ في
 * الإعدادات مبيعات المتجر.
 */
class EnsureOnlineOrdersEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->enabled()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => __('storefront.orders_disabled_title'),
                'code' => 'online_orders_disabled',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->view('storefront.orders-disabled', [], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /** هل الطلب أونلاين مفعّل؟ الافتراض «نعم» حتى يُطفأ صراحةً. */
    public static function enabled(): bool
    {
        try {
            return (bool) Settings::get('store.online_orders_enabled', true);
        } catch (\Throwable) {
            return true;
        }
    }
}
