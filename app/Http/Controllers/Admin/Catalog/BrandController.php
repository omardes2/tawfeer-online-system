<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Services\BrandService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function __construct(private readonly BrandService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Brand::class);

        $brands = Brand::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.catalog.brands.index', compact('brands'));
    }

    public function create(): View
    {
        $this->authorize('create', Brand::class);

        return view('admin.catalog.brands.form', ['brand' => new Brand]);
    }

    public function store(StoreBrandRequest $request): RedirectResponse
    {
        $this->authorize('create', Brand::class);
        $this->service->create($request->validated());

        return redirect()->route('admin.brands.index')->with('success', __('تم إنشاء العلامة التجارية.'));
    }

    public function edit(Brand $brand): View
    {
        $this->authorize('update', $brand);

        return view('admin.catalog.brands.form', ['brand' => $brand]);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);
        $this->service->update($brand, $request->validated());

        return redirect()->route('admin.brands.index')->with('success', __('تم تحديث العلامة التجارية.'));
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->authorize('delete', $brand);
        $this->service->delete($brand);

        return redirect()->route('admin.brands.index')->with('success', __('تم حذف العلامة التجارية.'));
    }
}
