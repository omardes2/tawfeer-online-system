<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductAttributeRequest;
use App\Http\Requests\Catalog\StoreProductAttributeValueRequest;
use App\Http\Requests\Catalog\UpdateProductAttributeRequest;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\ProductAttributeService;
use App\Modules\Catalog\Services\ProductAttributeValueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductAttributeController extends Controller
{
    public function __construct(
        private readonly ProductAttributeService $service,
        private readonly ProductAttributeValueService $valueService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProductAttribute::class);

        $attributes = ProductAttribute::query()->withCount('values')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('sort_order')->paginate(15)->withQueryString();

        return view('admin.catalog.attributes.index', compact('attributes'));
    }

    public function create(): View
    {
        $this->authorize('create', ProductAttribute::class);

        return view('admin.catalog.attributes.form', ['attribute' => new ProductAttribute]);
    }

    public function store(StoreProductAttributeRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductAttribute::class);
        $attribute = $this->service->create($request->validated());

        return redirect()->route('admin.attributes.edit', $attribute)->with('success', __('تم إنشاء السمة. أضف قيمها الآن.'));
    }

    public function edit(ProductAttribute $attribute): View
    {
        $this->authorize('update', $attribute);

        return view('admin.catalog.attributes.form', [
            'attribute' => $attribute->load('values'),
        ]);
    }

    public function update(UpdateProductAttributeRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->authorize('update', $attribute);
        $this->service->update($attribute, $request->validated());

        return redirect()->route('admin.attributes.index')->with('success', __('تم تحديث السمة.'));
    }

    public function destroy(ProductAttribute $attribute): RedirectResponse
    {
        $this->authorize('delete', $attribute);
        $this->service->delete($attribute);

        return redirect()->route('admin.attributes.index')->with('success', __('تم حذف السمة.'));
    }

    public function storeValue(StoreProductAttributeValueRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->authorize('update', $attribute);
        $this->valueService->create($attribute, $request->validated());

        return back()->with('success', __('تمت إضافة القيمة.'));
    }

    public function destroyValue(ProductAttribute $attribute, ProductAttributeValue $value): RedirectResponse
    {
        $this->authorize('update', $attribute);
        abort_unless($value->attribute_id === $attribute->id, 404);
        $this->valueService->delete($value);

        return back()->with('success', __('تم حذف القيمة.'));
    }
}
