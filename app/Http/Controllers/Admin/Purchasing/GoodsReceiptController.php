<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreGoodsReceiptRequest;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\GoodsReceiptService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        return view('admin.purchasing.receipts.index', [
            'receipts' => GoodsReceipt::with(['purchaseOrder', 'warehouse'])->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', GoodsReceipt::class);

        $order = null;
        if ($request->filled('purchase_order')) {
            $order = PurchaseOrder::with(['supplier', 'warehouse', 'items.variant'])
                ->where('uuid', $request->string('purchase_order'))->firstOrFail();
        }

        return view('admin.purchasing.receipts.form', [
            'order' => $order,
            'openOrders' => PurchaseOrder::with('supplier')->whereIn('status', ['approved', 'partially_received'])->latest('id')->get(),
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $po = PurchaseOrder::where('uuid', $request->validated('purchase_order'))->firstOrFail();

        $items = collect($request->validated('items'))
            ->filter(fn ($i) => (float) $i['qty_received'] > 0)
            ->map(fn ($i) => [
                'variant_id' => ProductVariant::where('uuid', $i['variant'])->value('id'),
                'purchase_order_item_id' => $i['purchase_order_item_id'] ?? null,
                'qty_received' => $i['qty_received'],
                'unit_cost' => $i['unit_cost'],
            ])->values()->all();

        if (empty($items)) {
            return back()->withInput()->with('error', __('حدّد كمية استلام واحدة على الأقل.'));
        }

        try {
            $receipt = $this->service->create($po, [
                'additional_cost' => $request->validated('additional_cost', 0),
                'notes' => $request->validated('notes'),
            ], $items, (int) now()->year);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.purchasing.receipts.show', $receipt)->with('success', __('أُنشئ إيصال الاستلام.'));
    }

    public function show(GoodsReceipt $receipt): View
    {
        $this->authorize('view', $receipt);

        return view('admin.purchasing.receipts.show', [
            'receipt' => $receipt->load(['purchaseOrder', 'warehouse', 'items.variant']),
        ]);
    }

    public function post(GoodsReceipt $receipt): RedirectResponse
    {
        $this->authorize('post', $receipt);

        try {
            $this->service->post($receipt);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('رُحّل الاستلام وزاد المخزون عبر المحرّك.'));
    }
}
