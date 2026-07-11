<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductTagRequest;
use App\Http\Requests\Catalog\UpdateProductTagRequest;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Services\ProductTagService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductTagController extends Controller
{
    public function __construct(private readonly ProductTagService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProductTag::class);

        $tags = ProductTag::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.catalog.tags.index', compact('tags'));
    }

    public function create(): View
    {
        $this->authorize('create', ProductTag::class);

        return view('admin.catalog.tags.form', ['tag' => new ProductTag]);
    }

    public function store(StoreProductTagRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductTag::class);
        $this->service->create($request->validated());

        return redirect()->route('admin.tags.index')->with('success', __('تم إنشاء الوسم.'));
    }

    public function edit(ProductTag $tag): View
    {
        $this->authorize('update', $tag);

        return view('admin.catalog.tags.form', ['tag' => $tag]);
    }

    public function update(UpdateProductTagRequest $request, ProductTag $tag): RedirectResponse
    {
        $this->authorize('update', $tag);
        $this->service->update($tag, $request->validated());

        return redirect()->route('admin.tags.index')->with('success', __('تم تحديث الوسم.'));
    }

    public function destroy(ProductTag $tag): RedirectResponse
    {
        $this->authorize('delete', $tag);
        $this->service->delete($tag);

        return redirect()->route('admin.tags.index')->with('success', __('تم حذف الوسم.'));
    }
}
