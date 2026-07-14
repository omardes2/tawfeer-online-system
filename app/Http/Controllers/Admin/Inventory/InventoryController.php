<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IssueStockRequest;
use App\Http\Requests\Inventory\ReceiveStockRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryLedger;
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
        private readonly ProductService $products,
    ) {}

    /** صفحة «المخزن»: عرض الأصناف بالأسعار والكمية المتوفرة (متمركزة حول المنتج). */
    public function stocks(Request $request): View
    {
        $this->authorize('inventory.stocks.view');

        $products = Product::query()->with('category:id,name')
            ->withSum('stocks as on_hand_sum', 'on_hand')
            ->withSum('stocks as reserved_sum', 'reserved')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.inventory.stocks', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'search' => $request->input('search'),
            'activeCategory' => $request->integer('category') ?: null,
        ]);
    }

    /** كرت الصنف: بياناته + سجل المخزن (كل حركات المخزون على الصنف). */
    public function showProduct(Product $product): View
    {
        $this->authorize('inventory.stocks.view');

        $variantIds = $product->variants()->pluck('id');

        $ledger = InventoryLedger::query()
            ->whereIn('variant_id', $variantIds)
            ->with(['warehouse:id,name', 'movement:id,reason,reference_type,reference_id'])
            ->latest('id')->paginate(20);

        $onHand = (float) InventoryStock::whereIn('variant_id', $variantIds)->sum('on_hand');
        $reserved = (float) InventoryStock::whereIn('variant_id', $variantIds)->sum('reserved');

        return view('admin.inventory.product-ledger', [
            'product' => $product->load('category:id,name'),
            'ledger' => $ledger,
            'available' => $onHand - $reserved,
            'onHand' => $onHand,
        ]);
    }

    /** صفحة تعديل سريع لصنف من المخزن: الفئة، كود المنتج، سعر الشراء/البيع/الجملة. */
    public function editProduct(Product $product): View
    {
        $this->authorize('update', $product);

        return view('admin.inventory.product-edit', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku,'.$product->id],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->products->update($product, $data);

        return redirect()->route('admin.inventory.stocks')->with('success', __('حُدّث الصنف.'));
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
