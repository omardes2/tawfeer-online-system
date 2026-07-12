<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shipping\GeographyResource;
use App\Http\Resources\Shipping\ShippingZoneResource;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GeographyController extends Controller
{
    public function governorates(Request $request): AnonymousResourceCollection
    {
        $this->authorizeGeo();

        return GeographyResource::collection(
            Governorate::query()->orderBy('sort_order')->orderBy('name')->paginate($this->perPage())
        );
    }

    public function cities(Request $request): AnonymousResourceCollection
    {
        $this->authorizeGeo();

        $query = City::query();
        if ($request->filled('governorate_id')) {
            $query->where('governorate_id', $request->integer('governorate_id'));
        }

        return GeographyResource::collection($query->orderBy('sort_order')->orderBy('name')->paginate($this->perPage()));
    }

    public function areas(Request $request): AnonymousResourceCollection
    {
        $this->authorizeGeo();

        $query = Area::query();
        if ($request->filled('city_id')) {
            $query->where('city_id', $request->integer('city_id'));
        }

        return GeographyResource::collection($query->orderBy('sort_order')->orderBy('name')->paginate($this->perPage()));
    }

    public function shippingZones(Request $request): AnonymousResourceCollection
    {
        $this->authorizeGeo();

        return ShippingZoneResource::collection(
            ShippingZone::query()->with(['cities', 'areas'])->orderBy('name')->paginate($this->perPage())
        );
    }

    private function authorizeGeo(): void
    {
        abort_unless(request()->user()?->can('settings.geography.view'), 403);
    }
}
