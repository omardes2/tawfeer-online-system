<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseOrderRequest;
use App\Http\Requests\Purchasing\UpdatePurchaseOrderRequest;
use App\Http\Resources\Purchasing\PurchaseOrderResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::query()->with(['supplier', 'warehouse']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('supplier')) {
            $supplierId = Supplier::where('uuid', $request->string('supplier'))->value('id');
            $query->where('supplier_id', $supplierId);
        }

        $this->applySorting($query, $request, ['number', 'order_date', 'created_at']);

        return PurchaseOrderResource::collection($query->paginate($this->perPage()));
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->load(['supplier', 'warehouse', 'items']));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $po = $this->service->create($this->header($request), $this->items($request), (int) now()->year);

        return (new PurchaseOrderResource($po->load(['supplier', 'warehouse', 'items'])))->response()->setStatusCode(201);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('update', $purchaseOrder);

        $items = $request->has('items') ? $this->items($request) : null;
        $po = $this->service->update($purchaseOrder, $this->header($request, false), $items);

        return new PurchaseOrderResource($po->load(['supplier', 'warehouse', 'items']));
    }

    public function submit(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('update', $purchaseOrder);

        return new PurchaseOrderResource($this->service->submit($purchaseOrder)->load(['supplier', 'warehouse', 'items']));
    }

    public function approve(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('approve', $purchaseOrder);

        return new PurchaseOrderResource($this->service->approve($purchaseOrder)->load(['supplier', 'warehouse', 'items']));
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('cancel', $purchaseOrder);

        return new PurchaseOrderResource($this->service->cancel($purchaseOrder)->load(['supplier', 'warehouse', 'items']));
    }

    public function close(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('close', $purchaseOrder);

        return new PurchaseOrderResource($this->service->close($purchaseOrder)->load(['supplier', 'warehouse', 'items']));
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('delete', $purchaseOrder);

        if (! in_array($purchaseOrder->status, ['draft', 'pending_approval', 'cancelled'], true)) {
            return response()->json(['message' => __('لا يمكن حذف أمر شراء بعد اعتماده أو استلامه.')], 422);
        }

        $purchaseOrder->delete();

        return response()->json(['message' => __('تم حذف أمر الشراء.')]);
    }

    private function header(Request $request, bool $required = true): array
    {
        $data = [];

        if ($request->filled('supplier')) {
            $data['supplier_id'] = Supplier::where('uuid', $request->string('supplier'))->value('id');
        }
        if ($request->filled('warehouse')) {
            $warehouse = Warehouse::where('uuid', $request->string('warehouse'))->firstOrFail();
            $data['warehouse_id'] = $warehouse->id;
            $data['branch_id'] = $warehouse->branch_id;
        }
        foreach (['order_date', 'expected_date', 'notes'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        return $data;
    }

    private function items(Request $request): array
    {
        return collect($request->input('items', []))->map(fn ($item) => [
            'variant_id' => ProductVariant::where('uuid', $item['variant'])->value('id'),
            'qty_ordered' => $item['qty_ordered'],
            'unit_cost' => $item['unit_cost'],
            'discount' => $item['discount'] ?? 0,
        ])->all();
    }
}
