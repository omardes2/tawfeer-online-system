<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\CompleteProfileRequest;
use App\Modules\Crm\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * إكمال الملف بعد الدخول الاجتماعي (Phase 3.5): جمع الهاتف/الميلاد/اللغة/تفضيلات
 * التواصل المطلوبة قبل الشراء. المنطق في CustomerService (لا تكرار).
 */
class ProfileCompletionController extends Controller
{
    use InteractsWithCustomer;

    public function __construct(private readonly CustomerService $customers) {}

    public function show(): View|RedirectResponse
    {
        $customer = $this->currentCustomer();
        if ($customer->isProfileComplete()) {
            return redirect()->route('account.dashboard');
        }

        return view('storefront.account.complete-profile', [
            'customer' => $customer,
            'locales' => (array) config('storefront.locales', ['ar', 'en']),
        ]);
    }

    public function store(CompleteProfileRequest $request): RedirectResponse
    {
        $customer = $this->currentCustomer();
        $data = $request->validated();

        $this->customers->update($customer, ['primary_phone' => $data['phone'], 'birth_date' => $data['birth_date']]);
        $this->customers->updatePreferences($customer, [
            'preferred_locale' => $data['preferred_locale'],
            'communication_preferences' => $data['communication_preferences'] ?? [],
        ]);

        // مزامنة هاتف المستخدم أيضًا.
        $request->user()->update(['phone' => $data['phone']]);

        return redirect()->intended(route('account.dashboard'))->with('status', __('account.profile_saved'));
    }
}
