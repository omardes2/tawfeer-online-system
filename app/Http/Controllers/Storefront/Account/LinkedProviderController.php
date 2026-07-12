<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Modules\Store\Services\SocialAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * إدارة مزوّدي الدخول المربوطين (Phase 3.5): عرض/ربط/فكّ الربط. الربط يبدأ عبر
 * SocialAuthController (نيّة link)؛ فكّ الربط عبر SocialAuthService (مع حماية آخر وسيلة).
 */
class LinkedProviderController extends Controller
{
    public function __construct(private readonly SocialAuthService $service) {}

    public function index(Request $request): View
    {
        $linked = $request->user()->socialIdentities()->pluck('provider')->all();

        return view('storefront.account.providers', [
            'providers' => (array) config('social.providers', []),
            'linked' => $linked,
        ]);
    }

    public function unlink(Request $request, string $provider): RedirectResponse
    {
        try {
            $this->service->unlink($request->user(), $provider);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('account.provider_unlinked'));
    }
}
