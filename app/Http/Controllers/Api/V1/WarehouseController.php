<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\StoreWarehouseRequest;
use App\Http\Requests\Structure\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Warehouse::query()->with('branch')->withCount('locations');

        if ($request->filled('branch')) {
            $branchId = Branch::where('uuid', $request->input('branch'))->value('id');
            $query->where('branch_id', $branchId);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        return WarehouseResource::collection($query->latest('id')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreWarehouseRequest $request): WarehouseResource
    {
        $warehouse = DB::transaction(function () use ($request) {
            $data = $request->payload();
            $warehouse = Warehouse::create($data);
            $this->syncDefault($warehouse);

            return $warehouse;
        });

        return new WarehouseResource($warehouse->load('branch')->loadCount('locations'));
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($warehouse->load('branch')->loadCount('locations'));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        DB::transaction(function () use ($request, $warehouse) {
            $warehouse->update($request->validated());
            $this->syncDefault($warehouse);
        });

        return new WarehouseResource($warehouse->fresh()->load('branch')->loadCount('locations'));
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        DB::transaction(function () use ($warehouse) {
            // نظّف مرجع المستودع الافتراضي للفرع قبل الحذف الناعم.
            Branch::where('default_warehouse_id', $warehouse->id)->update(['default_warehouse_id' => null]);
            $warehouse->delete();
        });

        return response()->json(['message' => __('تم حذف المستودع.')]);
    }

    /**
     * ضمان وجود مستودع افتراضي واحد فقط لكل فرع.
     */
    private function syncDefault(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            Warehouse::where('branch_id', $warehouse->branch_id)
                ->whereKeyNot($warehouse->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
