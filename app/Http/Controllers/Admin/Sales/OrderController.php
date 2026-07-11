<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CancelOrderRequest;
use App\Http\Requests\Sales\StoreOrderRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', Order::class);

        return view('admin.sales.orders.index', [
            'orders' => Order::with(['warehouse'])->latest('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);

        return view('admin.sales.orders.form', [
            'warehouses' => Warehouse::orderBy('name')->get(),
            'products' => Product::with('defaultVariant')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $warehouse = Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail();
        $items = collect($request->validated('items'))->map(fn ($i) => [
            'variant_id' => ProductVariant::where('uuid', $i['variant'])->value('id'),
            'qty' => $i['qty'],
            'unit_price' => $i['unit_price'],
            'discount' => $i['discount'] ?? 0,
        ])->all();

        $order = $this->service->create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'customer_name' => $request->validated('customer_name'),
            'customer_phone' => $request->validated('customer_phone'),
            'customer_email' => $request->validated('customer_email'),
            'shipping_address' => $request->validated('shipping_address'),
            'channel' => $request->validated('channel', 'manual'),
            'notes' => $request->validated('notes'),
        ], $items, (int) now()->year);

        return redirect()->route('admin.sales.orders.show', $order)->with('success', __('أُنشئ الطلب.'));
    }

    public function show(Order $order): View
    {
        $this->authorize('view', $order);

        return view('admin.sales.orders.show', [
            'order' => $order->load(['warehouse', 'items.variant', 'statusHistory.changedBy']),
        ]);
    }

    public function confirm(Order $order): RedirectResponse
    {
        $this->authorize('confirm', $order);

        return $this->guard($order, fn () => $this->service->confirm($order), __('تم تأكيد الطلب.'));
    }

    public function reserve(Order $order): RedirectResponse
    {
        $this->authorize('reserve', $order);

        return $this->guard($order, fn () => $this->service->reserveStock($order), __('تم حجز المخزون للطلب.'));
    }

    public function prepare(Order $order): RedirectResponse
    {
        $this->authorize('ship', $order);

        return $this->guard($order, fn () => $this->service->startPreparing($order), __('بدأ تجهيز الطلب.'));
    }

    public function ready(Order $order): RedirectResponse
    {
        $this->authorize('ship', $order);

        return $this->guard($order, fn () => $this->service->markReady($order), __('الطلب جاهز للشحن.'));
    }

    public function ship(Order $order): RedirectResponse
    {
        $this->authorize('ship', $order);

        return $this->guard($order, fn () => $this->service->ship($order), __('تم شحن الطلب وخصم المخزون.'));
    }

    public function deliver(Order $order): RedirectResponse
    {
        $this->authorize('deliver', $order);

        return $this->guard($order, fn () => $this->service->deliver($order), __('تم تسليم الطلب.'));
    }

    public function cancel(CancelOrderRequest $request, Order $order): RedirectResponse
    {
        $this->authorize('cancel', $order);

        return $this->guard($order, fn () => $this->service->cancel($order, $request->validated('reason')), __('أُلغي الطلب وحُرّر الحجز.'));
    }

    private function guard(Order $order, callable $fn, string $success): RedirectResponse
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
