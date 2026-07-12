<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Services\InventoryCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * جرد المخزون الفعلي (Phase 5 / ADR-043) — RTL. المنطق في InventoryCountService.
 */
class InventoryCountController extends Controller
{
    public function __construct(private readonly InventoryCountService $service) {}

    public function index(Request $request): View
    {
        return view('admin.inventory.counts.index', [
            'counts' => InventoryCount::with('warehouse:id,name')->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::firstOrFail();
        $count = $this->service->open($warehouse, [], 'full', $request->user());

        return redirect()->route('admin.inventory.counts.show', $count)->with('status', __('warehouse.count_opened'));
    }

    public function show(InventoryCount $count): View
    {
        return view('admin.inventory.counts.show', [
            'count' => $count->load(['warehouse:id,name', 'items.variant.product', 'countedBy:id,name']),
        ]);
    }

    public function record(InventoryCount $count, Request $request): RedirectResponse
    {
        if ($request->filled('barcode')) {
            $data = $request->validate(['barcode' => ['required', 'string'], 'counted_qty' => ['required', 'numeric', 'gte:0']]);
            $this->service->recordByBarcode($count, $data['barcode'], (float) $data['counted_qty']);
        } else {
            $data = $request->validate(['variant_id' => ['required', 'integer'], 'counted_qty' => ['required', 'numeric', 'gte:0']]);
            $this->service->recordCount($count, (int) $data['variant_id'], (float) $data['counted_qty']);
        }

        return back()->with('status', __('warehouse.count_recorded'));
    }

    public function recordBatch(InventoryCount $count, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.counted_qty' => ['required', 'numeric', 'gte:0'],
        ]);
        $this->service->recordBatch($count, $data['items']);

        return back()->with('status', __('warehouse.count_recorded'));
    }

    public function review(InventoryCount $count, Request $request): RedirectResponse
    {
        $this->service->review($count, $request->user());

        return back()->with('status', __('warehouse.count_review'));
    }

    public function complete(InventoryCount $count, Request $request): RedirectResponse
    {
        $this->service->complete($count, $request->user());

        return back()->with('status', __('warehouse.count_completed'));
    }

    public function cancel(InventoryCount $count, Request $request): RedirectResponse
    {
        $this->service->cancel($count);

        return back()->with('status', __('warehouse.count_cancelled'));
    }
}
