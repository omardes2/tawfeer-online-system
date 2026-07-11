<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseOrderRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        return view('admin.purchasing.orders.index', [
            'orders' => PurchaseOrder::with(['supplier', 'warehouse'])->latest('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', PurchaseOrder::class);

        return view('admin.purchasing.orders.form', $this->formData());
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $warehouse = Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail();
        $supplierId = Supplier::where('uuid', $request->validated('supplier'))->value('id');

        $items = collect($request->validated('items'))->map(fn ($i) => [
            'variant_id' => ProductVariant::where('uuid', $i['variant'])->value('id'),
            'qty_ordered' => $i['qty_ordered'],
            'unit_cost' => $i['unit_cost'],
            'discount' => $i['discount'] ?? 0,
        ])->all();

        $po = $this->service->create([
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'order_date' => $request->validated('order_date'),
            'expected_date' => $request->validated('expected_date'),
            'notes' => $request->validated('notes'),
        ], $items, (int) now()->year);

        return redirect()->route('admin.purchasing.orders.show', $po)->with('success', __('أُنشئ أمر الشراء.'));
    }

    public function show(PurchaseOrder $order): View
    {
        $this->authorize('view', $order);

        return view('admin.purchasing.orders.show', [
            'order' => $order->load(['supplier', 'warehouse', 'items.variant', 'receipts']),
        ]);
    }

    public function submit(PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('update', $order);
        $this->guard(fn () => $this->service->submit($order));

        return back()->with('success', __('أُرسل أمر الشراء للاعتماد.'));
    }

    public function approve(PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('approve', $order);
        $this->guard(fn () => $this->service->approve($order));

        return back()->with('success', __('اعتُمد أمر الشراء.'));
    }

    public function cancel(PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('cancel', $order);
        $this->guard(fn () => $this->service->cancel($order));

        return back()->with('success', __('أُلغي أمر الشراء.'));
    }

    public function close(PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('close', $order);
        $this->guard(fn () => $this->service->close($order));

        return back()->with('success', __('أُغلق أمر الشراء.'));
    }

    private function formData(): array
    {
        return [
            'order' => new PurchaseOrder,
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
            'products' => Product::with('defaultVariant')->orderBy('name')->get(),
        ];
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
