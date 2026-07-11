<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductTagRequest;
use App\Http\Requests\Catalog\UpdateProductTagRequest;
use App\Http\Resources\Catalog\ProductTagResource;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Services\ProductTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductTagController extends Controller
{
    public function __construct(private readonly ProductTagService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductTag::class);

        $query = ProductTag::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
        }

        $this->applySorting($query, $request, ['name', 'created_at'], 'name', 'asc');

        return ProductTagResource::collection($query->paginate($this->perPage()));
    }

    public function store(StoreProductTagRequest $request): ProductTagResource
    {
        $this->authorize('create', ProductTag::class);

        return new ProductTagResource($this->service->create($request->validated()));
    }

    public function show(ProductTag $tag): ProductTagResource
    {
        $this->authorize('view', $tag);

        return new ProductTagResource($tag);
    }

    public function update(UpdateProductTagRequest $request, ProductTag $tag): ProductTagResource
    {
        $this->authorize('update', $tag);

        return new ProductTagResource($this->service->update($tag, $request->validated()));
    }

    public function destroy(ProductTag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);

        $this->service->delete($tag);

        return response()->json(['message' => __('تم حذف الوسم.')]);
    }
}
