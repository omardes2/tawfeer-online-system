<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * مسح كاش التطبيق من لوحة التحكم بدل سطر الأوامر: config + route + view + cache.
 * محميّ بصلاحية settings.system.manage (يُفحص في المسار).
 */
class ClearCacheController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        foreach (['cache:clear', 'config:clear', 'route:clear', 'view:clear'] as $command) {
            Artisan::call($command);
        }

        return back()->with('success', __('تم مسح الكاش بنجاح.'));
    }
}
