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
use App\Modules\Shipping\Jobs\CancelOrderShipment;
use App\Modules\Shipping\Jobs\DispatchOrderShipment;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Modules\Shipping\Support\OpostStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /** حالات الطلب القانونية للفلترة (بالترتيب المنطقي). */
    private const STATUSES = ['draft', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

    public function __construct(private readonly OrderService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Order::class);

        $status = $request->query('status');
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        // فلترة بحالة أوبتيموس الخام (كما ترد من المزوّد).
        $deliveryStatus = $request->query('delivery_status');
        $deliveryStatus = array_key_exists((string) $deliveryStatus, OpostStatus::options()) ? $deliveryStatus : null;

        $paymentStatus = $request->query('payment_status');
        $paymentStatus = in_array($paymentStatus, ['paid', 'unpaid', 'partial'], true) ? $paymentStatus : null;

        $query = Order::with(['assignee', 'creator', 'customer', 'latestShipment'])->latest('id');
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($deliveryStatus !== null) {
            $query->whereHas('shipments', fn ($q) => $q->where('provider_status', $deliveryStatus));
        }
        if ($paymentStatus !== null) {
            $paymentStatus === 'unpaid'
                ? $query->where(fn ($q) => $q->where('payment_status', 'unpaid')->orWhereNull('payment_status'))
                : $query->where('payment_status', $paymentStatus);
        }

        return view('admin.sales.orders.index', [
            'orders' => $query->paginate(20)->withQueryString(),
            'statuses' => self::STATUSES,
            'activeStatus' => $status,
            'activeDeliveryStatus' => $deliveryStatus,
            'activePaymentStatus' => $paymentStatus,
            'deliveryLabels' => OpostStatus::options(),
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
            // مدن أوبتيموس فقط (المزامَنة ولها سعر) — تضمن وجود ربط خارجي (تفادي رفض 422).
            'cities' => City::whereIn('id', DeliveryCityRate::where('is_active', true)->pluck('city_id')->filter())
                ->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'areas' => Area::whereIn('city_id', DeliveryCityRate::where('is_active', true)->pluck('city_id')->filter())
                ->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'city_id']),
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

        // عند التأكيد: إرسال الطلب لشركة التوصيل (Opost) في الخلفية عبر الطابور —
        // لا اتصال متزامن داخل طلب الويب (يتفادى مهلة الانتظار). رقم التتبّع يظهر بعد تنفيذ المهمة.
        if (empty($order->tracking_number) && config('shipping.provider', 'null') !== 'null') {
            DispatchOrderShipment::dispatch($order->id);

            return back()->with('success', __('تم تأكيد الطلب، ويجري إرساله لشركة التوصيل (يظهر رقم التتبّع خلال لحظات).'));
        }

        return back()->with('success', __('تم تأكيد الطلب.'));
    }

    /** حذف الطلب — مسموح فقط إذا كانت حالته «ملغى» وحالة توصيله «ملغاة». */
    public function destroy(Order $order): RedirectResponse
    {
        $this->authorize('delete', $order);

        if (! self::isDeletable($order)) {
            return back()->with('error', __('لا يمكن حذف الطلب إلا إذا كانت حالته «ملغى» وحالة التوصيل «ملغاة».'));
        }

        $order->delete(); // حذف ناعم (soft delete).

        return redirect()->route('admin.sales.orders.index')->with('success', __('تم حذف الطلب.'));
    }

    /** الطلب قابل للحذف فقط عند إلغائه وإلغاء شحنته لدى المزوّد (أوبتيموس). */
    public static function isDeletable(Order $order): bool
    {
        $s = $order->latestShipment;

        return $order->status === 'cancelled'
            && ($s?->provider_status === 'cancelled' || $s?->delivery_status === DeliveryStatus::CANCELLED);
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

        // المعرّف الخارجي قبل الإلغاء (للإلغاء لدى المزوّد لاحقًا).
        $sentToProvider = ! empty($order->delivery_external_id) || ! empty($order->tracking_number);

        try {
            $this->service->cancel($order, $request->validated('reason'));
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // إلغاء الشحنة من شركة التوصيل (Opost) في الخلفية إن كان الطلب قد أُرسل.
        if ($sentToProvider && config('shipping.provider', 'null') !== 'null') {
            CancelOrderShipment::dispatch($order->id);

            return back()->with('success', __('أُلغي الطلب وحُرّر الحجز، ويجري إلغاء الشحنة من شركة التوصيل.'));
        }

        return back()->with('success', __('أُلغي الطلب وحُرّر الحجز.'));
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
