<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * رؤى المستودع (Phase 5 / ADR-043): بحث باركود، تنبيهات نقص، لوحة مؤشّرات — للقراءة.
 */
class WarehouseInsightController extends Controller
{
    public function __construct(private readonly WarehouseService $service) {}

    private function warehouse(Request $request): Warehouse
    {
        return $request->filled('warehouse_id')
            ? Warehouse::findOrFail($request->query('warehouse_id'))
            : (Warehouse::where('is_default', true)->first() ?? Warehouse::firstOrFail());
    }

    /** بحث المتغيّر بالباركود الدقيق (مسح). */
    public function barcode(Request $request): JsonResponse
    {
        $variant = $this->service->findByBarcode((string) $request->query('code', ''));
        if ($variant === null) {
            return response()->json(['found' => false], 404);
        }
        $variant->loadMissing('product');

        return response()->json([
            'found' => true, 'variant_id' => $variant->id, 'sku' => $variant->sku,
            'name' => $variant->product?->name ?? $variant->name,
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        return response()->json([
            'items' => $this->service->lowStock($this->warehouse($request))->map(fn ($s) => [
                'variant_id' => $s->variant_id, 'sku' => $s->variant?->sku,
                'on_hand' => $s->on_hand, 'reorder_level' => $s->reorder_level, 'reorder_qty' => $s->reorder_qty,
            ]),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->service->dashboard($this->warehouse($request)));
    }
}
