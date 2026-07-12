<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryBatchService;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * لوحة المستودع + تنبيهات النقص + بحث الباركود + التسوية الدفعية (Phase 5 / ADR-043) — RTL.
 * للقراءة والعمليات عبر الخدمات القائمة (لا تكرار منطق).
 */
class WarehouseController extends Controller
{
    public function __construct(
        private readonly WarehouseService $service,
        private readonly InventoryBatchService $batch,
    ) {}

    private function warehouse(Request $request): Warehouse
    {
        return $request->filled('warehouse')
            ? Warehouse::where('uuid', $request->query('warehouse'))->firstOrFail()
            : (Warehouse::where('is_default', true)->first() ?? Warehouse::firstOrFail());
    }

    public function dashboard(Request $request): View
    {
        $warehouse = $this->warehouse($request);

        return view('admin.inventory.warehouse', [
            'warehouse' => $warehouse,
            'kpi' => $this->service->dashboard($warehouse),
        ]);
    }

    public function lowStock(Request $request): View
    {
        $warehouse = $this->warehouse($request);

        return view('admin.inventory.low_stock', [
            'warehouse' => $warehouse,
            'rows' => $this->service->lowStock($warehouse),
        ]);
    }

    /** بحث باركود (JSON) — للمسح في شاشات الجرد/التسوية. */
    public function barcode(Request $request): JsonResponse
    {
        $variant = $this->service->findByBarcode((string) $request->query('code', ''));
        if ($variant === null) {
            return response()->json(['found' => false], 404);
        }
        $variant->loadMissing('product');

        return response()->json([
            'found' => true,
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'name' => $variant->product?->name ?? $variant->name,
        ]);
    }

    public function batch(Request $request): View
    {
        return view('admin.inventory.batch', ['warehouse' => $this->warehouse($request)]);
    }

    public function batchStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.delta' => ['required', 'numeric'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $lines = collect($data['lines'])->filter(fn ($l) => (float) $l['delta'] != 0.0)->values()->all();
        $applied = $this->batch->adjust($this->warehouse($request), $lines, $data['reason']);

        return back()->with('status', __('warehouse.batch_applied', ['n' => $applied]));
    }
}
