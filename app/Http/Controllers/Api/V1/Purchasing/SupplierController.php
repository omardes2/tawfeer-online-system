<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierRequest;
use App\Http\Requests\Purchasing\UpdateSupplierRequest;
use App\Http\Resources\Purchasing\SupplierResource;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Supplier::class);

        $query = Supplier::query();

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $this->applySorting($query, $request, ['name', 'code', 'created_at']);

        return SupplierResource::collection($query->paginate($this->perPage()));
    }

    public function show(Supplier $supplier): SupplierResource
    {
        $this->authorize('view', $supplier);

        return new SupplierResource($supplier->load('contacts'));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->safe()->except('contacts');
        $supplier = $this->service->create($data, $request->validated('contacts', []));

        return (new SupplierResource($supplier->load('contacts')))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $this->authorize('update', $supplier);

        $data = $request->safe()->except('contacts');
        $contacts = $request->has('contacts') ? $request->validated('contacts', []) : null;
        $supplier = $this->service->update($supplier, $data, $contacts);

        return new SupplierResource($supplier->load('contacts'));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorize('delete', $supplier);

        $this->service->delete($supplier);

        return response()->json(['message' => __('تم حذف المورد.')]);
    }
}
