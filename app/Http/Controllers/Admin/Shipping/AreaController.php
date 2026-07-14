<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * إدارة مناطق الشحن (المناطق الفرعية داخل كل مدينة): إضافة/تعديل/تفعيل/حذف.
 * الصلاحيات: عرض = settings.geography.view · تعديل = settings.geography.manage.
 */
class AreaController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('settings.geography.view');

        $cities = City::orderBy('name')->get(['id', 'name']);
        $cityId = $request->integer('city') ?: $cities->first()?->id;

        $areas = Area::where('city_id', $cityId)
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.shipping.areas.index', [
            'cities' => $cities,
            'cityId' => $cityId,
            'areas' => $areas,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.geography.manage');

        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
        ]);

        Area::create([
            'city_id' => $data['city_id'],
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('admin.shipping.areas.index', ['city' => $data['city_id']])
            ->with('success', __('تمت إضافة المنطقة.'));
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.geography.manage');

        $rows = $request->validate([
            'areas' => ['array'],
            'areas.*.name' => ['nullable', 'string', 'max:150'],
            'areas.*.is_active' => ['nullable', 'boolean'],
        ])['areas'] ?? [];

        foreach ($rows as $id => $vals) {
            $area = Area::find($id);
            if (! $area) {
                continue;
            }
            $area->update([
                'name' => $vals['name'] ?: $area->name,
                'is_active' => (bool) ($vals['is_active'] ?? false),
            ]);
        }

        return back()->with('success', __('حُفظت المناطق.'));
    }

    public function destroy(Area $area): RedirectResponse
    {
        $this->authorize('settings.geography.manage');

        $cityId = $area->city_id;
        $area->delete();

        return redirect()->route('admin.shipping.areas.index', ['city' => $cityId])
            ->with('success', __('حُذفت المنطقة.'));
    }
}
