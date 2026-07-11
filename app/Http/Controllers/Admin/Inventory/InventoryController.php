<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IssueStockRequest;
use App\Http\Requests\Inventory\ReceiveStockRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ReservationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReservationService $reservations,
    ) {}

    public function stocks(Request $request): View
    {
        $this->authorize('inventory.stocks.view');

        $stocks = InventoryStock::query()->with(['variant.product', 'warehouse'])
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse')))
            ->orderByDesc('updated_at')->paginate(20)->withQueryString();

        return view('admin.inventory.stocks', ['stocks' => $stocks, 'warehouses' => Warehouse::orderBy('name')->get()]);
    }

    public function movements(Request $request): View
    {
        $this->authorize('inventory.movements.view');

        $movements = InventoryMovement::query()->with(['variant', 'warehouse'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.inventory.movements', compact('movements'));
    }

    public function reservations(Request $request): View
    {
        $this->authorize('viewAny', StockReservation::class);

        $reservations = StockReservation::query()->with(['variant', 'warehouse'])
            ->latest('id')->paginate(20);

        return view('admin.inventory.reservations', compact('reservations'));
    }

    public function releaseReservation(StockReservation $reservation): RedirectResponse
    {
        $this->authorize('release', $reservation);

        try {
            $this->reservations->release($reservation);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم تحرير الحجز.'));
    }

    public function operations(): View
    {
        $this->authorize('inventory.stocks.view');

        return view('admin.inventory.operations', [
            'products' => Product::with('defaultVariant')->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }

    public function receive(ReceiveStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.receive');
        [$variant, $warehouse] = $this->resolve($request);
        $this->inventory->receive($variant, $warehouse, (float) $request->validated('qty'), (float) $request->validated('unit_cost'), $request->only(['reason', 'note']));

        return back()->with('success', __('تم استلام المخزون.'));
    }

    public function issue(IssueStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.issue');
        [$variant, $warehouse] = $this->resolve($request);

        try {
            $this->inventory->issue($variant, $warehouse, (float) $request->validated('qty'), $request->only(['reason', 'note']));
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم صرف المخزون.'));
    }

    public function transfer(TransferStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.transfer');
        $variant = ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail();
        $from = Warehouse::where('uuid', $request->validated('from_warehouse'))->firstOrFail();
        $to = Warehouse::where('uuid', $request->validated('to_warehouse'))->firstOrFail();

        try {
            $this->inventory->transfer($variant, $from, $to, (float) $request->validated('qty'), $request->only(['reason', 'note']));
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم التحويل بين المستودعين.'));
    }

    private function resolve($request): array
    {
        return [
            ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail(),
            Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail(),
        ];
    }
}
