<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * ملف العميل وإعدادات الحساب (Phase 3.4): تعديل البيانات (عبر CustomerService)
 * وتغيير كلمة المرور. الملف والمستخدم يبقيان متزامنين.
 */
class ProfileController extends Controller
{
    use InteractsWithCustomer;

    public function __construct(private readonly CustomerService $customers) {}

    public function edit(): View
    {
        return view('storefront.account.profile', ['customer' => $this->currentCustomer()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $customer = $this->currentCustomer();
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:40'],
            'birth_date' => ['required', 'date', 'before:today'],
        ]);

        $this->customers->update($customer, [
            'name' => $data['name'],
            'email' => $data['email'],
            'primary_phone' => $data['phone'],
            'birth_date' => $data['birth_date'],
        ]);
        $user->update(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone']]);

        return back()->with('status', __('account.profile_saved'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('status', __('account.password_saved'));
    }
}
