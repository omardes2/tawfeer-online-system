<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\ShippingZone;
use Illuminate\Contracts\View\View;

class GeographyController extends Controller
{
    public function index(): View
    {
        abort_unless(request()->user()?->can('settings.geography.view'), 403);

        return view('admin.shipping.geography.index', [
            'governorates' => Governorate::with('cities')->orderBy('sort_order')->orderBy('name')->get(),
            'zones' => ShippingZone::orderBy('name')->get(),
        ]);
    }
}
