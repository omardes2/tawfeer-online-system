<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\StoreBranchRequest;
use App\Http\Requests\Structure\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Modules\Foundation\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Branch::query()->withCount('warehouses');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        return BranchResource::collection($query->latest('id')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreBranchRequest $request): BranchResource
    {
        $branch = DB::transaction(function () use ($request) {
            $branch = Branch::create($request->validated());
            $this->syncDefault($branch);

            return $branch;
        });

        return new BranchResource($branch->loadCount('warehouses'));
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch->load('defaultWarehouse')->loadCount('warehouses'));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        DB::transaction(function () use ($request, $branch) {
            $branch->update($request->validated());
            $this->syncDefault($branch);
        });

        return new BranchResource($branch->fresh()->load('defaultWarehouse')->loadCount('warehouses'));
    }

    public function destroy(Branch $branch): JsonResponse
    {
        if ($branch->is_default) {
            return response()->json(['message' => __('لا يمكن حذف الفرع الافتراضي.')], 422);
        }

        $branch->delete();

        return response()->json(['message' => __('تم حذف الفرع.')]);
    }

    /**
     * ضمان وجود فرع افتراضي واحد فقط على مستوى النظام.
     */
    private function syncDefault(Branch $branch): void
    {
        if ($branch->is_default) {
            Branch::whereKeyNot($branch->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
