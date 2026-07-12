<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * مركز الإشعارات (Phase 3.4): صندوق داخل التطبيق (قناة database) مع تعليم كمقروء.
 * القنوات الخارجية تُوجَّه مستقبلًا عبر MessagingManager (ADR-030) — بلا تنفيذ الآن.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('storefront.account.notifications', [
            'notifications' => $request->user()->notifications()->paginate(15),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->first()?->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', __('account.notifications_all_read'));
    }
}
