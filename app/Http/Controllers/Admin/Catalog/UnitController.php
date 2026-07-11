<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitRequest;
use App\Http\Requests\Catalog\UpdateUnitRequest;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\UnitService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Unit::class);

        $units = Unit::query()->with('baseUnit')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')->orWhere('code', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.catalog.units.index', compact('units'));
    }

    public function create(): View
    {
        $this->authorize('create', Unit::class);

        return view('admin.catalog.units.form', ['unit' => new Unit, 'units' => Unit::orderBy('name')->get()]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $this->authorize('create', Unit::class);
        $this->service->create($request->validated());

        return redirect()->route('admin.units.index')->with('success', __('تم إنشاء الوحدة.'));
    }

    public function edit(Unit $unit): View
    {
        $this->authorize('update', $unit);

        return view('admin.catalog.units.form', ['unit' => $unit, 'units' => Unit::whereKeyNot($unit->id)->orderBy('name')->get()]);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $this->authorize('update', $unit);
        $this->service->update($unit, $request->validated());

        return redirect()->route('admin.units.index')->with('success', __('تم تحديث الوحدة.'));
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $this->authorize('delete', $unit);

        try {
            $this->service->delete($unit);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.units.index')->with('success', __('تم حذف الوحدة.'));
    }
}
