<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductAttributeRequest;
use App\Http\Requests\Catalog\UpdateProductAttributeRequest;
use App\Http\Resources\Catalog\ProductAttributeResource;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Services\ProductAttributeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductAttributeController extends Controller
{
    public function __construct(private readonly ProductAttributeService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductAttribute::class);

        $query = ProductAttribute::query()->withCount('values');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
        }

        $this->applySorting($query, $request, ['name', 'sort_order', 'created_at'], 'sort_order', 'asc');

        return ProductAttributeResource::collection($query->paginate($this->perPage()));
    }

    public function store(StoreProductAttributeRequest $request): ProductAttributeResource
    {
        $this->authorize('create', ProductAttribute::class);

        return new ProductAttributeResource($this->service->create($request->validated()));
    }

    public function show(ProductAttribute $attribute): ProductAttributeResource
    {
        $this->authorize('view', $attribute);

        return new ProductAttributeResource($attribute->load('values')->loadCount('values'));
    }

    public function update(UpdateProductAttributeRequest $request, ProductAttribute $attribute): ProductAttributeResource
    {
        $this->authorize('update', $attribute);

        return new ProductAttributeResource($this->service->update($attribute, $request->validated()));
    }

    public function destroy(ProductAttribute $attribute): JsonResponse
    {
        $this->authorize('delete', $attribute);

        $this->service->delete($attribute);

        return response()->json(['message' => __('تم حذف السمة.')]);
    }
}
