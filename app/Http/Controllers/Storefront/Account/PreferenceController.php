<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\PreferencesRequest;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * تفضيلات العميل (Phase 3.4): اللغة، الفرع المفضّل، تفضيلات التواصل.
 * جاهزية تسويق/أتمتة مستقبلًا — تُخزَّن فقط، بلا منطق نمو الآن.
 */
class PreferenceController extends Controller
{
    use InteractsWithCustomer;

    public function __construct(private readonly CustomerService $customers) {}

    public function edit(): View
    {
        return view('storefront.account.preferences', [
            'customer' => $this->currentCustomer(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'locales' => (array) config('storefront.locales', ['ar', 'en']),
        ]);
    }

    public function update(PreferencesRequest $request): RedirectResponse
    {
        $this->customers->updatePreferences($this->currentCustomer(), $request->validated());

        return back()->with('status', __('account.preferences_saved'));
    }
}
