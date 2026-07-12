<?php

namespace App\Http\Middleware;

use App\Modules\Crm\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يضمن اكتمال ملف العميل قبل الشراء (Phase 3.5): مستخدمو الدخول الاجتماعي ناقصو
 * البيانات (هاتف/ميلاد/لغة/تفضيلات) يُوجَّهون لإكمال الملف. الضيوف يمرّون (سلة ضيف).
 */
class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $customer = Customer::where('user_id', auth()->id())->first();
            if ($customer && ! $customer->isProfileComplete()) {
                return redirect()->route('account.profile.complete');
            }
        }

        return $next($request);
    }
}
