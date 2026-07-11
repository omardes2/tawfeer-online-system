<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockAdjustmentRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StockAdjustmentController extends Controller
{
    public function __construct(private readonly StockAdjustmentService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $adjustments = StockAdjustment::query()->with('warehouse')->latest('id')->paginate(20);

        return view('admin.inventory.adjustments.index', compact('adjustments'));
    }

    public function create(): View
    {
        $this->authorize('create', StockAdjustment::class);

        return view('admin.inventory.adjustments.form', [
            'warehouses' => Warehouse::orderBy('name')->get(),
            'products' => Product::with('defaultVariant')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreStockAdjustmentRequest $request): RedirectResponse
    {
        $this->authorize('create', StockAdjustment::class);

        $warehouse = Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail();
        $items = collect($request->validated('items'))->map(fn ($i) => [
            'variant_id' => ProductVariant::where('uuid', $i['variant'])->value('id'),
            'qty_counted' => $i['qty_counted'],
            'unit_cost' => $i['unit_cost'] ?? 0,
            'note' => $i['note'] ?? null,
        ])->all();

        $adjustment = $this->service->create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'type' => $request->validated('type', 'recount'),
            'reason' => $request->validated('reason'),
            'notes' => $request->validated('notes'),
        ], $items, (int) now()->year);

        return redirect()->route('admin.inventory.adjustments.show', $adjustment)->with('success', __('أُنشئت التسوية.'));
    }

    public function show(StockAdjustment $adjustment): View
    {
        $this->authorize('view', $adjustment);

        return view('admin.inventory.adjustments.show', ['adjustment' => $adjustment->load(['warehouse', 'items.variant'])]);
    }

    public function approve(StockAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('approve', $adjustment);
        $this->guard(fn () => $this->service->approve($adjustment));

        return back()->with('success', __('تم اعتماد التسوية.'));
    }

    public function post(StockAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('post', $adjustment);
        $this->guard(fn () => $this->service->post($adjustment));

        return back()->with('success', __('تم ترحيل التسوية وتطبيقها على المخزون.'));
    }

    public function destroy(StockAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('delete', $adjustment);

        if ($adjustment->status === 'posted') {
            return back()->with('error', __('لا يمكن حذف تسوية مُرحّلة.'));
        }
        $adjustment->delete();

        return redirect()->route('admin.inventory.adjustments.index')->with('success', __('تم حذف التسوية.'));
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }
}
