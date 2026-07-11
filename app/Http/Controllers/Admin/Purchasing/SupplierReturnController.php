<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierReturnRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Services\SupplierReturnService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class SupplierReturnController extends Controller
{
    public function __construct(private readonly SupplierReturnService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', SupplierReturn::class);

        return view('admin.purchasing.returns.index', [
            'returns' => SupplierReturn::with(['supplier', 'warehouse'])->latest('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', SupplierReturn::class);

        return view('admin.purchasing.returns.form', [
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
            'products' => Product::with('defaultVariant')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreSupplierReturnRequest $request): RedirectResponse
    {
        $this->authorize('create', SupplierReturn::class);

        $supplier = Supplier::where('uuid', $request->validated('supplier'))->firstOrFail();
        $warehouse = Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail();
        $poId = $request->filled('purchase_order')
            ? PurchaseOrder::where('uuid', $request->validated('purchase_order'))->value('id')
            : null;

        $items = collect($request->validated('items'))->map(fn ($i) => [
            'variant_id' => ProductVariant::where('uuid', $i['variant'])->value('id'),
            'qty' => $i['qty'],
            'unit_cost' => $i['unit_cost'] ?? 0,
        ])->all();

        $return = $this->service->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'purchase_order_id' => $poId,
            'branch_id' => $warehouse->branch_id,
            'reason' => $request->validated('reason'),
            'notes' => $request->validated('notes'),
        ], $items, (int) now()->year);

        return redirect()->route('admin.purchasing.returns.show', $return)->with('success', __('أُنشئ المرتجع.'));
    }

    public function show(SupplierReturn $return): View
    {
        $this->authorize('view', $return);

        return view('admin.purchasing.returns.show', [
            'return' => $return->load(['supplier', 'warehouse', 'items.variant']),
        ]);
    }

    public function approve(SupplierReturn $return): RedirectResponse
    {
        $this->authorize('approve', $return);
        $this->guard(fn () => $this->service->approve($return));

        return back()->with('success', __('اعتُمد المرتجع.'));
    }

    public function post(SupplierReturn $return): RedirectResponse
    {
        $this->authorize('post', $return);

        try {
            $this->service->post($return);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('رُحّل المرتجع وخفّض المخزون عبر المحرّك.'));
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
