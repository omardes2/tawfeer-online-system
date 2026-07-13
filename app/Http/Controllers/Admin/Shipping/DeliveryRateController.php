<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Shipping\Services\DeliveryRateSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * إدارة أسعار توصيل شركة التوصيل لكل مدينة (نمط Opost): عرض/تعديل رسوم التوصيل والإرجاع،
 * إضافة سعر لمدينة محلّية، ومزامنة المدن من المزوّد بزرّ واحد.
 * الصلاحيات: عرض = settings.geography.view · تعديل/مزامنة = settings.geography.manage.
 */
class DeliveryRateController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('settings.geography.view');

        $rates = DeliveryCityRate::with(['city', 'provider'])
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        // مدن محلّية لا سعر لها بعد (لإضافتها يدويًا).
        $usedCityIds = $rates->pluck('city_id')->filter()->all();
        $unpricedCities = City::whereNotIn('id', $usedCityIds ?: [0])
            ->orderBy('name')->get(['id', 'name', 'name_en']);

        $provider = DeliveryProvider::where('code', 'opost')->first();
        $lastSync = $rates->max('synced_at');

        return view('admin.shipping.delivery_rates.index', compact('rates', 'unpricedCities', 'provider', 'lastSync'));
    }

    /** إضافة سعر لمدينة محلّية (إدارة يدوية بلا مزامنة). */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.geography.manage');

        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'delivery_fee' => ['required', 'numeric', 'min:0'],
            'return_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $city = City::findOrFail($data['city_id']);
        $provider = DeliveryProvider::firstOrCreate(
            ['code' => 'opost'],
            ['name' => 'Opost', 'driver' => 'opost', 'is_active' => true],
        );

        DeliveryCityRate::updateOrCreate(
            ['delivery_provider_id' => $provider->id, 'city_id' => $city->id],
            [
                'name' => $city->name,
                'name_en' => $city->name_en,
                'delivery_fee' => $data['delivery_fee'],
                'return_fee' => $data['return_fee'] ?? 0,
                'currency' => strtoupper($data['currency'] ?? 'ILS'),
                'is_active' => true,
            ],
        );

        return back()->with('success', __('تمت إضافة سعر المدينة.'));
    }

    /** تعديل جماعي لرسوم التوصيل/الإرجاع والتفعيل. */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.geography.manage');

        $rows = $request->validate([
            'rates' => ['array'],
            'rates.*.delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'rates.*.return_fee' => ['nullable', 'numeric', 'min:0'],
            'rates.*.is_active' => ['nullable', 'boolean'],
        ])['rates'] ?? [];

        foreach ($rows as $id => $vals) {
            $rate = DeliveryCityRate::find($id);
            if (! $rate) {
                continue;
            }
            $rate->update([
                'delivery_fee' => $vals['delivery_fee'] ?? $rate->delivery_fee,
                'return_fee' => $vals['return_fee'] ?? $rate->return_fee,
                'is_active' => (bool) ($vals['is_active'] ?? false),
            ]);
        }

        return back()->with('success', __('حُفظت أسعار التوصيل.'));
    }

    /** مزامنة المدن (والأسعار إن توفّرت) من شركة التوصيل. */
    public function sync(DeliveryRateSyncService $service): RedirectResponse
    {
        $this->authorize('settings.geography.manage');

        if (! config('services.opost.token')) {
            return back()->with('error', __('لم يُضبط توكن Opost في الخادم (OPOST_API_TOKEN).'));
        }

        $r = $service->sync('opost');

        return back()->with('success', __('اكتملت المزامنة: :synced مدينة (:linked مرتبطة، :priced مُسعّرة من المزوّد).', $r));
    }

    public function destroy(DeliveryCityRate $deliveryRate): RedirectResponse
    {
        $this->authorize('settings.geography.manage');
        $deliveryRate->delete();

        return back()->with('success', __('حُذف سعر المدينة.'));
    }
}
