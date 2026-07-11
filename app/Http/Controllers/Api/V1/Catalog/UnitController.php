<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitRequest;
use App\Http\Requests\Catalog\UpdateUnitRequest;
use App\Http\Resources\Catalog\UnitResource;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\UnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Unit::class);

        $query = Unit::query()->with('baseUnit');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        $this->applySorting($query, $request, ['name', 'code', 'created_at'], 'name', 'asc');

        return UnitResource::collection($query->paginate($this->perPage()));
    }

    public function store(StoreUnitRequest $request): UnitResource
    {
        $this->authorize('create', Unit::class);

        return new UnitResource($this->service->create($request->validated()));
    }

    public function show(Unit $unit): UnitResource
    {
        $this->authorize('view', $unit);

        return new UnitResource($unit->load('baseUnit'));
    }

    public function update(UpdateUnitRequest $request, Unit $unit): UnitResource
    {
        $this->authorize('update', $unit);

        return new UnitResource($this->service->update($unit, $request->validated()));
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $this->authorize('delete', $unit);

        $this->service->delete($unit);

        return response()->json(['message' => __('تم حذف الوحدة.')]);
    }
}
