<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CancelOrderRequest;
use App\Http\Requests\Sales\StoreOrderRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /** حالات الطلب القانونية للفلترة (بالترتيب المنطقي). */
    private const STATUSES = ['draft', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

    public function __construct(
        private readonly OrderService $service,
        private readonly OrderDeliveryDispatcher $dispatcher,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Order::class);

        $status = $request->query('status');
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        $query = Order::with(['assignee', 'creator', 'customer', 'latestShipment'])->latest('id');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return view('admin.sales.orders.index', [
            'orders' => $query->paginate(20)->withQueryString(),
            'statuses' => self::STATUSES,
            'activeStatus' => $status,
            'statusCounts' => Order::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'totalCount' => Order::count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);

        // بطاقات المنتجات للبحث بالاسم مع صورة مصغّرة وسعر (نمط نقطة البيع).
        $products = Product::query()->active()
            ->with(['defaultVariant', 'primaryImage'])
            ->orderBy('name')
            ->get()
            ->filter(fn ($p) => $p->defaultVariant)
            ->map(fn ($p) => [
                'name' => $p->name,
                'sku' => $p->sku,
                'variant' => $p->defaultVariant->uuid,
                'price' => (float) $p->defaultVariant->retail_price,
                'image' => $p->primaryImage?->url(),
            ])->values();

        return view('admin.sales.orders.form', [
            // مستودع افتراضي واحد (مخفيّ في الواجهة) — يُحلّ تلقائيًا.
            'warehouse' => $this->defaultWarehouse(),
            'products' => $products,
            'cities' => City::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'areas' => Area::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'city_id']),
            // خريطة سعر التوصيل لكل مدينة (نمط Opost) لحساب حيّ في الواجهة.
            'cityRates' => DeliveryCityRate::where('is_active', true)->pluck('delivery_fee', 'city_id'),
        ]);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $warehouse = $request->filled('warehouse')
            ? Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail()
            : $this->defaultWarehouse();

        abort_if($warehouse === null, 422, __('لا يوجد مستودع مُهيّأ.'));

        $items = collect($request->validated('items'))->map(fn ($i) => [
            'variant_id' => ProductVariant::where('uuid', $i['variant'])->value('id'),
            'qty' => $i['qty'],
            'unit_price' => $i['unit_price'],
            'discount' => $i['discount'] ?? 0,
        ])->all();

        $cityId = $request->validated('city_id');

        $order = $this->service->create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'customer_name' => $request->validated('customer_name'),
            'customer_phone' => $request->validated('customer_phone'),
            'customer_email' => $request->validated('customer_email'),
            'shipping_address' => $request->validated('shipping_address'),
            'city_id' => $cityId,
            'area_id' => $request->validated('area_id'),
            'has_return' => $request->boolean('has_return'),
            'return_notes' => $request->validated('return_notes'),
            'shipping_total' => $this->deliveryFeeFor($cityId),
            'channel' => $request->validated('channel', 'manual'),
            'notes' => $request->validated('notes'),
        ], $items, (int) now()->year);

        return redirect()->route('admin.sales.orders.show', $order)->with('success', __('أُنشئ الطلب.'));
    }

    public function show(Order $order): View
    {
        $this->authorize('view', $order);

        return view('admin.sales.orders.show', [
            'order' => $order->load(['warehouse', 'city', 'area', 'items.variant', 'statusHistory.changedBy']),
        ]);
    }

    public function confirm(Order $order): RedirectResponse
    {
        $this->authorize('confirm', $order);

        try {
            $this->service->confirm($order);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // عند التأكيد: إرسال الطلب لشركة التوصيل (Opost) وتخزين رقم التتبّع.
        // فشل التكامل لا يُلغي التأكيد — يُسجَّل تحذيرًا ويُعاد المحاولة لاحقًا.
        $result = $this->dispatcher->dispatch($order);

        if ($result['status'] === 'created') {
            return back()->with('success', __('تم تأكيد الطلب وإرساله لشركة التوصيل (تتبّع: :n).', ['n' => $result['tracking_number']]));
        }
        if ($result['status'] === 'skipped') {
            return back()->with('success', __('تم تأكيد الطلب.'));
        }

        return back()->with('warning', __('تم تأكيد الطلب، لكن تعذّر إرساله لشركة التوصيل: :msg', ['msg' => $result['message'] ?? __('خطأ غير معروف')]));
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

    /** المستودع الافتراضي (النظام أحادي المستودع حاليًا). */
    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    /** سعر توصيل المدينة من جدول أسعار المزوّد (نمط Opost) — 0 إن لم يُضبط. */
    private function deliveryFeeFor(?int $cityId): float
    {
        if ($cityId === null) {
            return 0.0;
        }

        return (float) (DeliveryCityRate::where('is_active', true)
            ->where('city_id', $cityId)
            ->value('delivery_fee') ?? 0);
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
