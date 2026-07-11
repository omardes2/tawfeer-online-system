<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\Catalog\BrandResource;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandController extends Controller
{
    public function __construct(private readonly BrandService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Brand::class);

        $query = Brand::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
        }

        $this->applySorting($query, $request, ['name', 'created_at'], 'name', 'asc');

        return BrandResource::collection($query->paginate($this->perPage()));
    }

    public function store(StoreBrandRequest $request): BrandResource
    {
        $this->authorize('create', Brand::class);

        return new BrandResource($this->service->create($request->validated()));
    }

    public function show(Brand $brand): BrandResource
    {
        $this->authorize('view', $brand);

        return new BrandResource($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        $this->authorize('update', $brand);

        return new BrandResource($this->service->update($brand, $request->validated()));
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('delete', $brand);

        $this->service->delete($brand);

        return response()->json(['message' => __('تم حذف العلامة التجارية.')]);
    }
}
