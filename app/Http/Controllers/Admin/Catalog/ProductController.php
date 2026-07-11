<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductImageRequest;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\ProductImageService;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
        private readonly ProductImageService $imageService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()->with(['category', 'brand', 'unit', 'primaryImage'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')->orWhere('sku', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('sort_order')->paginate(15)->withQueryString();

        return view('admin.catalog.products.index', compact('products'));
    }

    public function create(): View
    {
        $this->authorize('create', Product::class);

        return view('admin.catalog.products.form', $this->formData(new Product));
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $product = $this->service->create($request->validated());

        return redirect()->route('admin.products.edit', $product)->with('success', __('تم إنشاء المنتج. يمكنك الآن رفع الصور.'));
    }

    public function edit(Product $product): View
    {
        $this->authorize('update', $product);

        return view('admin.catalog.products.form', $this->formData($product->load(['tags', 'attributes', 'images'])));
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $this->service->update($product, $request->validated());

        return redirect()->route('admin.products.index')->with('success', __('تم تحديث المنتج.'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        $this->service->delete($product);

        return redirect()->route('admin.products.index')->with('success', __('تم حذف المنتج.'));
    }

    public function storeImage(StoreProductImageRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $this->imageService->store($product, $request->file('image'), $request->safe()->except('image'));

        return back()->with('success', __('تم رفع الصورة.'));
    }

    public function setPrimaryImage(Product $product, ProductImage $image): RedirectResponse
    {
        $this->authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);
        $this->imageService->setPrimary($product, $image);

        return back()->with('success', __('تم تعيين الصورة الأساسية.'));
    }

    public function destroyImage(Product $product, ProductImage $image): RedirectResponse
    {
        $this->authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);
        $this->imageService->delete($image);

        return back()->with('success', __('تم حذف الصورة.'));
    }

    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
            'tags' => ProductTag::orderBy('name')->get(),
            'attributes' => ProductAttribute::orderBy('name')->get(),
        ];
    }
}
