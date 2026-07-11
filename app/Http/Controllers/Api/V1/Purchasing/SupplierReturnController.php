<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierReturnRequest;
use App\Http\Resources\Purchasing\SupplierReturnResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Services\SupplierReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierReturnController extends Controller
{
    public function __construct(private readonly SupplierReturnService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierReturn::class);

        $query = SupplierReturn::query()->with(['supplier', 'warehouse']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return SupplierReturnResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function show(SupplierReturn $supplierReturn): SupplierReturnResource
    {
        $this->authorize('view', $supplierReturn);

        return new SupplierReturnResource($supplierReturn->load(['supplier', 'warehouse', 'items']));
    }

    public function store(StoreSupplierReturnRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierReturn::class);

        $return = $this->service->create($this->header($request), $this->items($request), (int) now()->year);

        return (new SupplierReturnResource($return->load(['supplier', 'warehouse', 'items'])))->response()->setStatusCode(201);
    }

    public function approve(SupplierReturn $supplierReturn): SupplierReturnResource
    {
        $this->authorize('approve', $supplierReturn);

        return new SupplierReturnResource($this->service->approve($supplierReturn)->load(['supplier', 'warehouse', 'items']));
    }

    public function post(SupplierReturn $supplierReturn): SupplierReturnResource
    {
        $this->authorize('post', $supplierReturn);

        return new SupplierReturnResource($this->service->post($supplierReturn)->load(['supplier', 'warehouse', 'items']));
    }

    private function header(StoreSupplierReturnRequest $request): array
    {
        $supplier = Supplier::where('uuid', $request->validated('supplier'))->firstOrFail();
        $warehouse = Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail();
        $poId = $request->filled('purchase_order')
            ? PurchaseOrder::where('uuid', $request->validated('purchase_order'))->value('id')
            : null;

        return [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'purchase_order_id' => $poId,
            'branch_id' => $warehouse->branch_id,
            'reason' => $request->validated('reason'),
            'notes' => $request->validated('notes'),
        ];
    }

    private function items(StoreSupplierReturnRequest $request): array
    {
        return collect($request->validated('items'))->map(fn ($item) => [
            'variant_id' => ProductVariant::where('uuid', $item['variant'])->value('id'),
            'qty' => $item['qty'],
            'unit_cost' => $item['unit_cost'] ?? 0,
        ])->all();
    }
}
