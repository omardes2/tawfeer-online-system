<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CancelOrderRequest;
use App\Http\Requests\Sales\StoreDirectSaleRequest;
use App\Http\Requests\Sales\StoreOrderRequest;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderPaymentService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Jobs\CancelOrderShipment;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Modules\Shipping\Support\OpostStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $search = trim((string) $request->query('search'));

        // نوع البيع: مباشر (channel=pos) أو عادي.
        $saleType = $request->query('sale_type');
        $saleType = in_array($saleType, ['direct', 'normal'], true) ? $saleType : null;

        $query = Order::with(['assignee', 'creator', 'customer', 'latestShipment'])->latest('id');

        match ($saleType) {
            'direct' => $query->where('channel', 'pos'),
            'normal' => $query->where('channel', '!=', 'pos'),
            default => null,
        };

        // بحث برقم التتبّع أو اسم المستلم أو اسم المستخدم (الموظف).
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('customer_name', 'like', $like)
                    ->orWhere('tracking_number', 'like', $like)
                    ->orWhereHas('latestShipment', fn ($s) => $s->where('recipient_name', 'like', $like)->orWhere('tracking_number', 'like', $like))
                    ->orWhereHas('assignee', fn ($u) => $u->where('name', 'like', $like))
                    ->orWhereHas('creator', fn ($u) => $u->where('name', 'like', $like));
            });
        }

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
            'activeSearch' => $search,
            'activeSaleType' => $saleType,
            'deliveryLabels' => OpostStatus::options(),
            'statusCounts' => Order::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'totalCount' => Order::count(),
            // خزائن التحصيل (نقدية/بنكية) لنافذة الدفع — مربوطة بحساب GL فقط.
            'treasuries' => Treasury::query()
                ->where('is_active', true)->whereNotNull('gl_account_id')
                ->orderByDesc('is_default')->orderBy('type')->orderBy('name')
                ->get(['id', 'name', 'type']),
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

    /** نموذج «مبيعات مباشرة» — بيع من المستودع بلا توصيل خارجي. */
    public function createDirect(): View
    {
        $this->authorize('create', Order::class);

        $products = Product::query()->active()
            ->with(['defaultVariant', 'primaryImage'])
            ->orderBy('name')->get()
            ->filter(fn ($p) => $p->defaultVariant)
            ->map(fn ($p) => [
                'name' => $p->name, 'sku' => $p->sku,
                'variant' => $p->defaultVariant->uuid,
                'price' => (float) $p->defaultVariant->retail_price,
                'image' => $p->primaryImage?->url(),
            ])->values();

        return view('admin.sales.orders.direct', [
            'products' => $products,
            // قائمة العملاء المسجّلين للاختيار (بديل عن كتابة الاسم يدويًا).
            'customers' => Customer::orderBy('name')
                ->get(['uuid', 'name', 'primary_phone'])
                ->map(fn ($c) => ['uuid' => $c->uuid, 'name' => $c->name, 'phone' => $c->primary_phone])->values(),
        ]);
    }

    public function storeDirect(StoreDirectSaleRequest $request): RedirectResponse
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

        // عميل مسجّل (اختيار) أو عميل جديد (كتابة الاسم).
        $customer = $request->filled('customer')
            ? Customer::where('uuid', $request->validated('customer'))->first()
            : null;

        $order = $this->service->create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name ?? $request->validated('customer_name'),
            'customer_phone' => $customer?->primary_phone ?? ($request->validated('customer_phone') ?? ''),
            'channel' => 'pos', // علامة «مبيعات مباشرة»
            'notes' => $request->validated('notes'),
        ], $items, (int) now()->year);

        try {
            $this->service->fulfillDirect($order);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.sales.orders.show', $order)
            ->with('success', __('تمت المبيعة المباشرة وخُصمت الكميات من المخزون.'));
    }

    public function show(Order $order): View
    {
        $this->authorize('view', $order);

        return view('admin.sales.orders.show', [
            'order' => $order->load(['warehouse', 'city', 'area', 'items.variant.product', 'statusHistory.changedBy']),
        ]);
    }

    public function confirm(Order $order, OrderDeliveryDispatcher $dispatcher): RedirectResponse
    {
        $this->authorize('confirm', $order);

        try {
            $this->service->confirm($order);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // الترحيل الجذري: إرسال الطلب لشركة التوصيل مباشرةً لحظة التأكيد (متزامن) — لا اعتماد
        // على طابور خلفية. إن تعذّر الإرسال الآن (Opost متوقّف لحظيًا) تلتقطه المكنسة المجدولة
        // (shipping:dispatch-pending) خلال دقيقة فتُعيد المحاولة حتى ينجح ⇒ وصول مضمون.
        if ($this->needsDelivery($order)) {
            $result = $dispatcher->dispatch($order);

            if (($result['status'] ?? null) === 'created') {
                return back()->with('success', __('تم تأكيد الطلب وإرساله لشركة التوصيل. رقم التتبّع: :n', ['n' => $result['tracking_number']]));
            }

            if (($result['status'] ?? null) === 'failed') {
                return back()->with('warning', __('تم تأكيد الطلب، وسيُعاد إرساله لشركة التوصيل تلقائيًا خلال دقيقة (تعذّر الإرسال الآن: :msg).', ['msg' => $result['message'] ?? '']));
            }
        }

        return back()->with('success', __('تم تأكيد الطلب.'));
    }

    /**
     * إعادة إرسال الطلب لشركة التوصيل يدويًا — للطلبات التي لم يظهر لها رقم تتبّع بعد.
     * idempotent (الحارس داخل الـDispatcher يمنع التكرار)، ومتاح فقط لطلبات التوصيل.
     */
    public function resendShipment(Order $order, OrderDeliveryDispatcher $dispatcher): RedirectResponse
    {
        $this->authorize('confirm', $order);

        if (! $this->needsDelivery($order)) {
            return back()->with('error', __('لا حاجة لإعادة الإرسال: الطلب مُرسَل مسبقًا أو ليس طلب توصيل.'));
        }

        $result = $dispatcher->dispatch($order);

        return match ($result['status'] ?? null) {
            'created' => back()->with('success', __('تم إرسال الطلب لشركة التوصيل. رقم التتبّع: :n', ['n' => $result['tracking_number']])),
            'skipped' => back()->with('warning', __('الطلب مُرسَل مسبقًا لشركة التوصيل.')),
            default => back()->with('error', __('تعذّر إرسال الطلب لشركة التوصيل: :msg', ['msg' => $result['message'] ?? ''])),
        };
    }

    /** طلب توصيل (له وجهة مدينة، وليس مبيعة مباشرة POS) لم يُرسَل بعد، ومزوّد التوصيل مُفعّل. */
    private function needsDelivery(Order $order): bool
    {
        return empty($order->tracking_number)
            && $order->channel !== 'pos'
            && $order->city_id !== null
            && config('shipping.provider', 'null') !== 'null';
    }

    /**
     * تأكيد استلام المرتجع في المستودع — متاح فقط عندما تكون حالة أوبتيموس «مرتجع مع السائق»
     * (delivered). عندها فقط تُعاد كميات الأصناف إلى المخزون (تظهر في سجل المخزن لكل صنف). idempotent.
     */
    public function receiveReturn(Order $order, InventoryService $inventory): RedirectResponse
    {
        $this->authorize('update', $order);

        if ($order->latestShipment?->provider_status !== 'delivered') {
            return back()->with('error', __('يتاح تأكيد الاستلام فقط عندما تكون حالة أوبتيموس «مرتجع مع السائق».'));
        }
        if ($order->return_received_at !== null) {
            return back()->with('error', __('سبق تأكيد استلام مرتجع هذا الطلب.'));
        }

        $warehouse = $order->warehouse
            ?? Warehouse::where('is_default', true)->first()
            ?? Warehouse::orderBy('id')->first();

        if (! $warehouse) {
            return back()->with('error', __('لا يوجد مستودع لإرجاع الكميات إليه.'));
        }

        DB::transaction(function () use ($order, $inventory, $warehouse) {
            foreach ($order->items as $item) {
                if (! $item->variant_id) {
                    continue;
                }
                $variant = ProductVariant::find($item->variant_id);
                if ($variant) {
                    $inventory->returnToStock($variant, $warehouse, (float) $item->qty, null, [
                        'reference_type' => Order::class,
                        'reference_id' => $order->id,
                        'reason' => 'order_return:'.$order->number,
                    ]);
                }
            }
            $order->update(['return_received_at' => now()]);
        });

        return back()->with('success', __('تم تأكيد استلام المرتجع وإرجاع الكميات إلى المخزون.'));
    }

    /**
     * تسديد مبلغ الطلب نقدًا — متاح للمبيعات المباشرة (pos) غير المسدَّدة فقط.
     * يسجّل دفعة أوفلاين بكامل المبلغ المتبقّي ويحصّلها فتصبح حالة الدفع «مدفوع».
     */
    public function settle(Request $request, Order $order, OrderPaymentService $orderPayments): RedirectResponse
    {
        $this->authorize('update', $order);

        if ($order->channel !== 'pos') {
            return back()->with('error', __('التسديد المباشر متاح للمبيعات المباشرة فقط.'));
        }
        if ($order->payment_status === 'paid') {
            return back()->with('error', __('الطلب مسدَّد بالفعل.'));
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
        ]);

        try {
            $orderPayments->collect($order, (int) $data['treasury_id'], (float) $data['amount']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('تم تحصيل الدفعة وترحيلها محاسبيًا.'));
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
