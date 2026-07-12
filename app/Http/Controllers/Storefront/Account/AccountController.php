<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * لوحة حساب العميل (Phase 3.4): نظرة عامّة — أحدث الطلبات، عناوين، مفضّلة، إشعارات.
 */
class AccountController extends Controller
{
    use InteractsWithCustomer;

    public function dashboard(Request $request): View
    {
        $customer = $this->currentCustomer();

        return view('storefront.account.dashboard', [
            'customer' => $customer,
            'recentOrders' => $customer->orders()->latest('id')->limit(5)->get(),
            'addressCount' => $customer->addresses()->count(),
            'wishlistCount' => $customer->wishlistItems()->count(),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }
}
