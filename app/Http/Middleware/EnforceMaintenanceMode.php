<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * وضع الصيانة الديناميكي (Production) — من إعدادات قاعدة البيانات (system.maintenance).
 * يُظهر 503 للزوّار عند التفعيل، ويسمح لمن يملك صلاحية لوحة التحكّم (المشرفون) بالمرور
 * لإدارة النظام. مُحصَّن: أي خطأ في قراءة الإعداد يعني "غير مفعّل" (فتح آمن).
 */
class EnforceMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $on = (bool) Settings::get('system.maintenance', false);
        } catch (\Throwable) {
            $on = false;
        }

        if ($on && ! ($request->user()?->can('dashboard.view') ?? false)) {
            abort(503, __('الموقع تحت الصيانة. يرجى المحاولة لاحقًا.'));
        }

        return $next($request);
    }
}
