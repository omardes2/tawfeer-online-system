<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\Catalog\ProductResource;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $query = Product::query()->with(['category', 'brand', 'unit']);

        if ($request->filled('category')) {
            $query->where('category_id', Category::where('uuid', $request->input('category'))->value('id'));
        }

        if ($request->filled('brand')) {
            $query->where('brand_id', Brand::where('uuid', $request->input('brand'))->value('id'));
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('product_tags.id', $request->integer('tag')));
        }

        foreach (['status', 'visibility', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }

        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('search_keywords', 'like', "%{$search}%"));
        }

        $this->applySorting($query, $request, ['name', 'sku', 'sort_order', 'created_at'], 'sort_order', 'asc');

        return ProductResource::collection($query->paginate($this->perPage()));
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $this->authorize('create', Product::class);

        $product = $this->service->create($request->validated());

        return new ProductResource($product->load(['category', 'brand', 'unit', 'tags', 'attributes', 'images']));
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load(['category', 'brand', 'unit', 'tags', 'attributes', 'images']));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $product = $this->service->update($product, $request->validated());

        return new ProductResource($product->load(['category', 'brand', 'unit', 'tags', 'attributes', 'images']));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->service->delete($product);

        return response()->json(['message' => __('تم حذف المنتج.')]);
    }
}
