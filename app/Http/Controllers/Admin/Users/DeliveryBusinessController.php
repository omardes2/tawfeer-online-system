<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeliveryBusinessRequest;
use App\Modules\Foundation\Models\DeliveryBusiness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * إدارة يدوية لحسابات «البزنس» لدى شركة التوصيل (بديل/مكمّل للمزامنة التلقائية).
 * الصلاحيات عبر middleware `can:settings.users.*` (routes). RTL.
 */
class DeliveryBusinessController extends Controller
{
    public function index(): View
    {
        return view('admin.users.delivery-businesses.index', [
            'businesses' => DeliveryBusiness::withCount('users')->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(DeliveryBusinessRequest $request): RedirectResponse
    {
        DeliveryBusiness::create([
            'provider' => $request->providerCode(),
            'external_id' => $request->string('external_id'),
            'name' => $request->string('name'),
            'address_external_id' => $request->input('address_external_id') ?: null,
            'phone' => $request->input('phone') ?: null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', __('أُضيف حساب البزنس.'));
    }

    public function update(DeliveryBusinessRequest $request, DeliveryBusiness $deliveryBusiness): RedirectResponse
    {
        $deliveryBusiness->update([
            'external_id' => $request->string('external_id'),
            'name' => $request->string('name'),
            'address_external_id' => $request->input('address_external_id') ?: null,
            'phone' => $request->input('phone') ?: null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', __('حُدّث حساب البزنس.'));
    }

    public function destroy(DeliveryBusiness $deliveryBusiness): RedirectResponse
    {
        // FK: users.delivery_business_id يُضبط null تلقائيًا عند الحذف.
        $deliveryBusiness->delete();

        return back()->with('success', __('حُذف حساب البزنس.'));
    }
}
