<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\StoreWarehouseLocationRequest;
use App\Http\Requests\Structure\UpdateWarehouseLocationRequest;
use App\Http\Resources\WarehouseLocationResource;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Models\WarehouseLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WarehouseLocationController extends Controller
{
    public function index(Request $request, Warehouse $warehouse): AnonymousResourceCollection
    {
        $query = $warehouse->locations()->getQuery();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }

        return WarehouseLocationResource::collection($query->orderBy('code')->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreWarehouseLocationRequest $request, Warehouse $warehouse): WarehouseLocationResource
    {
        $location = $warehouse->locations()->create($request->validated());

        return new WarehouseLocationResource($location);
    }

    public function show(Warehouse $warehouse, WarehouseLocation $location): WarehouseLocationResource
    {
        return new WarehouseLocationResource($location->load('children'));
    }

    public function update(UpdateWarehouseLocationRequest $request, Warehouse $warehouse, WarehouseLocation $location): WarehouseLocationResource
    {
        $location->update($request->validated());

        return new WarehouseLocationResource($location->fresh());
    }

    public function destroy(Warehouse $warehouse, WarehouseLocation $location): JsonResponse
    {
        $location->delete();

        return response()->json(['message' => __('تم حذف الموقع.')]);
    }
}
