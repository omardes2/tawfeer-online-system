<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Services\InventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * جرد المخزون الفعلي (Phase 5 / ADR-043). متحكّم رفيع — المنطق في InventoryCountService.
 */
class InventoryCountController extends Controller
{
    public function __construct(private readonly InventoryCountService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(InventoryCount::with('warehouse:id,name')->latest('id')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'variant_ids' => ['nullable', 'array'],
            'variant_ids.*' => ['integer'],
            'scope' => ['nullable', 'in:full,partial'],
        ]);
        $warehouse = isset($data['warehouse_id'])
            ? Warehouse::findOrFail($data['warehouse_id'])
            : (Warehouse::where('is_default', true)->first() ?? Warehouse::firstOrFail());

        $count = $this->service->open($warehouse, $data['variant_ids'] ?? [], $data['scope'] ?? 'full', $request->user());

        return response()->json($this->present($count->load('items')), 201);
    }

    public function show(InventoryCount $count): JsonResponse
    {
        return response()->json($this->present($count->load('items.variant:id,sku')));
    }

    public function record(InventoryCount $count, Request $request): JsonResponse
    {
        if ($request->filled('barcode')) {
            $data = $request->validate(['barcode' => ['required', 'string'], 'counted_qty' => ['required', 'numeric', 'gte:0']]);
            $this->service->recordByBarcode($count, $data['barcode'], (float) $data['counted_qty']);
        } else {
            $data = $request->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.variant_id' => ['required', 'integer'],
                'items.*.counted_qty' => ['required', 'numeric', 'gte:0'],
            ]);
            $this->service->recordBatch($count, $data['items']);
        }

        return response()->json($this->present($count->fresh('items')));
    }

    public function review(InventoryCount $count, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->review($count, $request->user())));
    }

    public function complete(InventoryCount $count, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->complete($count, $request->user())));
    }

    public function cancel(InventoryCount $count, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->cancel($count)));
    }

    private function present(InventoryCount $c): array
    {
        return [
            'id' => $c->uuid,
            'number' => $c->number,
            'status' => $c->status,
            'scope' => $c->scope,
            'adjusted_count' => $c->adjusted_count,
            'items' => $c->relationLoaded('items') ? $c->items->map(fn ($i) => [
                'variant_id' => $i->variant_id, 'system_qty' => $i->system_qty,
                'counted_qty' => $i->counted_qty, 'variance' => $i->variance, 'applied' => $i->applied,
            ]) : [],
        ];
    }
}
