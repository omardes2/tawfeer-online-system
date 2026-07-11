<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockAdjustmentRequest;
use App\Http\Resources\Inventory\StockAdjustmentResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockAdjustmentController extends Controller
{
    public function __construct(private readonly StockAdjustmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $query = StockAdjustment::query()->with('warehouse');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return StockAdjustmentResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function show(StockAdjustment $adjustment): StockAdjustmentResource
    {
        $this->authorize('view', $adjustment);

        return new StockAdjustmentResource($adjustment->load(['warehouse', 'items']));
    }

    public function store(StoreStockAdjustmentRequest $request): StockAdjustmentResource
    {
        $this->authorize('create', StockAdjustment::class);

        $adjustment = $this->service->create(
            $this->header($request),
            $this->items($request),
            (int) now()->year,
        );

        return new StockAdjustmentResource($adjustment->load(['warehouse', 'items']));
    }

    public function approve(StockAdjustment $adjustment): StockAdjustmentResource
    {
        $this->authorize('approve', $adjustment);

        return new StockAdjustmentResource($this->service->approve($adjustment)->load(['warehouse', 'items']));
    }

    public function post(StockAdjustment $adjustment): StockAdjustmentResource
    {
        $this->authorize('post', $adjustment);

        return new StockAdjustmentResource($this->service->post($adjustment)->load(['warehouse', 'items']));
    }

    public function destroy(StockAdjustment $adjustment): JsonResponse
    {
        $this->authorize('delete', $adjustment);

        if ($adjustment->status === 'posted') {
            return response()->json(['message' => __('لا يمكن حذف تسوية مُرحّلة.')], 422);
        }

        $adjustment->delete();

        return response()->json(['message' => __('تم حذف التسوية.')]);
    }

    private function header(StoreStockAdjustmentRequest $request): array
    {
        $warehouse = Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail();

        return [
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'type' => $request->validated('type', 'recount'),
            'reason' => $request->validated('reason'),
            'notes' => $request->validated('notes'),
        ];
    }

    private function items(StoreStockAdjustmentRequest $request): array
    {
        return collect($request->validated('items'))->map(fn ($item) => [
            'variant_id' => ProductVariant::where('uuid', $item['variant'])->value('id'),
            'qty_counted' => $item['qty_counted'],
            'unit_cost' => $item['unit_cost'] ?? 0,
            'note' => $item['note'] ?? null,
        ])->all();
    }
}
