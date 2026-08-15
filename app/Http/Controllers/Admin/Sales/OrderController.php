<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CancelOrderRequest;
use App\Http\Requests\Sales\StoreDirectSaleRequest;
use App\Http\Requests\Sales\StoreOrderRequest;
use App\Http\Requests\Sales\UpdateOrderContactRequest;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderPaymentService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\OrderVoidService;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Modules\Shipping\Support\OpostStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /** حالات الطلب القانونية للفلترة (بالترتيب المنطقي). */
    private const STATUSES = ['draft', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

    public function __construct(
        private readonly OrderService $service,
        private readonly CustomerService $customerService,
    ) {}

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

        // affiliate ضمن التحميل المسبق: عمود «المستخدم» يعرض المسوّق بصفته لا كموظف مبيعات.
        $query = $this->visibleOrders($request)->with(['affiliate', 'assignee', 'creator', 'customer', 'latestShipment'])->latest('id');

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
            // الحالة الداخلية لم تعد تُعرض في القائمة (المتابعة على حالة شركة التوصيل)،
            // لكن الفلترة بها تبقى مدعومة عبر ?status= للروابط المحفوظة والتقارير.
            'activeStatus' => $status,
            'activeDeliveryStatus' => $deliveryStatus,
            'activePaymentStatus' => $paymentStatus,
            'activeSearch' => $search,
            'activeSaleType' => $saleType,
            'deliveryLabels' => OpostStatus::options(),
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

        $warehouse = $this->defaultWarehouse();

        return view('admin.sales.orders.form', [
            // مستودع افتراضي واحد (مخفيّ في الواجهة) — يُحلّ تلقائيًا.
            'warehouse' => $warehouse,
            'products' => $this->productCards($warehouse),
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

        // ربط الطلب بمنشئه ليستحق عمولته عند in_accounting: موظف المبيعات يُسنَد إليه
        // (assigned_to)، والمسوّق يُسجَّل مسوّقًا (affiliate_id) — بدونهما لا مستفيد عمولة.
        $creator = $request->user();

        // الإنشاء والمعالجة في **معاملة واحدة**: أي فشل (ترحيل محاسبي، نقص مخزون…) يتراجع
        // بالطلب كلّه فلا يبقى طلب معلّق لا يُرحَّل ولا يصل شركة التوصيل. «تقديم الطلب» =
        // احتساب البيع فورًا (ترحيل + خصم مخزون حتى «الشحن»)، والإرسال للتوصيل خطوة تأكيد لاحقة.
        try {
            $order = DB::transaction(function () use ($request, $warehouse, $creator, $cityId, $items) {
                $order = $this->service->create([
                    'warehouse_id' => $warehouse->id,
                    'branch_id' => $warehouse->branch_id,
                    'assigned_to' => $creator->hasAnyRole(['sales', 'sales_supervisor']) ? $creator->id : null,
                    'affiliate_id' => $creator->hasRole('affiliate') ? $creator->id : null,
                    'customer_name' => $request->validated('customer_name'),
                    'customer_phone' => $request->validated('customer_phone'),
                    'customer_email' => $request->validated('customer_email'),
                    'shipping_address' => $request->validated('shipping_address'),
                    'city_id' => $cityId,
                    'area_id' => $request->validated('area_id'),
                    'has_return' => $request->boolean('has_return'),
                    'return_notes' => $request->validated('return_notes'),
                    'shipping_total' => $this->deliveryFeeFor($cityId),
                    'channel' => 'manual',
                    'notes' => $request->validated('notes'),
                ], $items, (int) now()->year);

                $this->service->fulfillToShipped($order);

                return $order;
            });
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', __('تعذّر تقديم الطلب ولم يُحفظ: :m', ['m' => $e->getMessage()]));
        }

        return redirect()->route('admin.sales.orders.show', $order)
            ->with('success', __('تم تقديم الطلب: خُصمت الكميات من المخزون ورُحّل محاسبيًا. بانتظار تأكيده لإرساله لشركة التوصيل.'));
    }

    /** نموذج «مبيعات مباشرة» — بيع من المستودع بلا توصيل خارجي. */
    public function createDirect(): View
    {
        $this->authorize('createDirect', Order::class);

        // نفس مصدر الأصناف المستخدم في «إنشاء أوردر»: بطاقة لكل مقاس/لون بكميته.
        $products = $this->productCards();

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
        $this->authorize('createDirect', Order::class);

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

        // عميل مسجّل (اختيار) أو عميل جديد: يُنشأ سجل عميل فعلي ويُضاف لقائمة العملاء.
        $customer = $request->filled('customer')
            ? Customer::where('uuid', $request->validated('customer'))->first()
            : $this->resolveNewDirectCustomer(
                $request->validated('customer_name'),
                $request->validated('customer_phone'),
                $warehouse->branch_id,
            );

        // كالطلب العادي: الإنشاء والمعالجة معاملة واحدة — الفشل لا يترك مبيعة معلّقة.
        try {
            $order = DB::transaction(function () use ($request, $warehouse, $customer, $items) {
                $order = $this->service->create([
                    'warehouse_id' => $warehouse->id,
                    'branch_id' => $warehouse->branch_id,
                    'customer_id' => $customer?->id,
                    'customer_name' => $customer?->name ?? $request->validated('customer_name'),
                    'customer_phone' => $customer?->primary_phone ?? ($request->validated('customer_phone') ?? ''),
                    'channel' => 'pos', // علامة «مبيعات مباشرة»
                    'notes' => $request->validated('notes'),
                ], $items, (int) now()->year);

                $this->service->fulfillDirect($order);

                return $order;
            });
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', __('تعذّرت المبيعة ولم تُحفظ: :m', ['m' => $e->getMessage()]));
        }

        return redirect()->route('admin.sales.orders.show', $order)
            ->with('success', __('تمت المبيعة المباشرة وخُصمت الكميات من المخزون.'));
    }

    /**
     * عميل جديد من شاشة المبيعات المباشرة: يُنشئ سجل عميل فعلي (source=pos) ويُضيفه
     * لقائمة العملاء مع حسابه المحاسبي. يُعيد استخدام عميل قائم بنفس رقم الهاتف بدل
     * تكرار السجل. الاسم إلزامي عبر التحقق، فلا حاجة لفحص إضافي.
     */
    private function resolveNewDirectCustomer(?string $name, ?string $phone, ?int $branchId): ?Customer
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        // منع التكرار: إن وُجد رقم هاتف مطابق لعميل قائم، استخدمه بدل إنشاء عميل جديد.
        $normalizedPhone = filled($phone) ? $this->customerService->normalizePhone($phone) : null;
        if ($normalizedPhone !== null) {
            $existing = Customer::where('primary_phone', $normalizedPhone)->first();
            if ($existing) {
                return $existing;
            }
        }

        return $this->customerService->create([
            'name' => $name,
            'primary_phone' => $phone,
            'branch_id' => $branchId,
            'source' => 'pos',
        ]);
    }

    public function show(Order $order): View
    {
        $this->authorize('view', $order);

        return view('admin.sales.orders.show', [
            'order' => $order->load(['warehouse', 'city', 'area', 'items.variant.product', 'statusHistory.changedBy']),
        ]);
    }

    /** فاتورة الطلب (عرض/طباعة) بتصميم رسمي — تُظهر المدفوع والمتبقّي وحالة الدفع. */
    public function invoice(Order $order): View
    {
        $this->authorize('view', $order);

        return view('admin.sales.orders.invoice', [
            'order' => $order->load(['city', 'area', 'items.variant.product', 'items.variant.attributeValues', 'customer', 'latestShipment']),
            // خزائن التحصيل (نقدية/بنكية) لنافذة الدفع الجزئي/الكامل — مربوطة بحساب GL فقط.
            'treasuries' => Treasury::query()
                ->where('is_active', true)->whereNotNull('gl_account_id')
                ->orderByDesc('is_default')->orderBy('type')->orderBy('name')
                ->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * تعديل بيانات التواصل/التوصيل لطلب قائم (تصحيح بيانات خاطئة: الاسم/الهاتف/البريد/العنوان/الملاحظات).
     * لا يمسّ الأصناف ولا القيود المحاسبية ولا المخزون. متاح ما لم يُرسَل الطلب لشركة التوصيل بعد.
     */
    public function edit(Order $order): View
    {
        $this->authorize('update', $order);

        abort_unless(self::isEditable($order), 403, __('لا يمكن تعديل هذا الطلب بعد إرساله لشركة التوصيل أو إلغائه/تسليمه.'));

        return view('admin.sales.orders.edit', [
            'order' => $order->load('items.variant.product'),
            'products' => $this->productCards(),
        ]);
    }

    public function update(UpdateOrderContactRequest $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        if (! self::isEditable($order)) {
            return redirect()->route('admin.sales.orders.show', $order)
                ->with('error', __('لا يمكن تعديل هذا الطلب بعد إرساله لشركة التوصيل أو إلغائه/تسليمه.'));
        }

        $items = collect($request->validated('items'))->map(fn ($i) => [
            'variant_id' => ProductVariant::where('uuid', $i['variant'])->value('id'),
            'qty' => $i['qty'],
            'unit_price' => $i['unit_price'],
            'discount' => $i['discount'] ?? 0,
        ])->all();

        // تعديل بيانات التواصل + الأصناف: تُزامَن الحركة المخزونية ويُحدَّث القيد المحاسبي
        // الموجود في مكانه (لا قيد جديد) للطلب المُرحّل.
        try {
            $this->service->editPostedOrder($order, [
                'customer_name' => $request->validated('customer_name'),
                'customer_phone' => $request->validated('customer_phone'),
                'customer_email' => $request->validated('customer_email'),
                'shipping_address' => $request->validated('shipping_address'),
                'notes' => $request->validated('notes'),
            ], $items);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.sales.orders.show', $order)
            ->with('success', __('تم تحديث الطلب وتحديث قيده المحاسبي وحركته المخزونية.'));
    }

    /**
     * الطلب قابل للتعديل (بيانات التواصل/التوصيل) ما لم يُرسَل لشركة التوصيل بعد
     * (لا رقم تتبّع ولا معرّف خارجي) ولم يُلغَ ولم يُسلَّم — فبعد الإرسال لن تتزامن التعديلات مع أوبتيموس.
     */
    public static function isEditable(Order $order): bool
    {
        return ! in_array($order->status, ['cancelled', 'delivered', 'returned'], true)
            && empty($order->tracking_number)
            && empty($order->delivery_external_id);
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
     * «تأكيد»: اعتماد المدير للطلبات المحدَّدة دفعةً واحدة.
     *
     * الاعتماد قرارُ مراجعة يُغلق الإلغاء في وجه مُدخِل الطلب. وهو غير التأكيد
     * الداخلي (`confirmed_at`) الذي يُرحّل الطلب ويرسله لشركة التوصيل: الطلب
     * الذي طردُه «بانتظار الاستلام» مؤكَّدٌ سلفًا، وما ينقصه الاعتماد وحده.
     * أمّا المسوّدة فتُؤكَّد وتُرسَل أولًا بنفس مسار الزرّ المفرد.
     *
     * تكامل التوصيل لا يُمَسّ، والاعتماد لا يُرسَل للمزوّد ولا يدخل أي حمولة.
     *
     * بلا معاملة جامعة عمدًا: التأكيد يُرسل لطرف خارجي، وتراجعُ معاملةٍ بعد
     * إرسال شحنة يترك النظام يخالف الواقع. كل طلب يقف بنفسه، والتقرير يذكر
     * كم نجح وكم تعذّر.
     */
    public function bulkConfirm(Request $request, OrderDeliveryDispatcher $dispatcher): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.orders.confirm'), 403);

        $ids = collect($request->input('ids', []))->filter()->map(fn ($v) => (int) $v)->all();
        if ($ids === []) {
            return back()->with('error', __('لم تُحدَّد أي طلبات للتأكيد.'));
        }

        $confirmed = 0;
        $skipped = 0;
        $pendingDispatch = 0;

        foreach (Order::with('latestShipment')->whereIn('id', $ids)->get() as $order) {
            if ($request->user()->cannot('approve', $order)) {
                $skipped++;

                continue;
            }

            // مسوّدة لم تُرسَل بعد: تُؤكَّد وتُرسَل أولًا بنفس مسار الزرّ المفرد.
            // وطلبٌ طردُه بانتظار الاستلام مؤكَّدٌ سلفًا، فلا يُعاد تأكيده.
            if ($order->confirmed_at === null && in_array($order->status, ['draft', 'new'], true)) {
                try {
                    $this->service->confirm($order);
                } catch (ValidationException $e) {
                    $skipped++;

                    continue;
                }

                if ($this->needsDelivery($order)) {
                    $result = $dispatcher->dispatch($order);
                    if (($result['status'] ?? null) !== 'created') {
                        $pendingDispatch++;
                    }
                }
            }

            // الاعتماد نفسه: يُغلق الإلغاء في وجه مُدخِل الطلب.
            $order->update(['approved_at' => now(), 'approved_by' => $request->user()->id]);
            $confirmed++;
        }

        if ($confirmed === 0) {
            return back()->with('error', __('لم يُؤكَّد أي طلب من المحدَّد.'));
        }

        $message = __('تم تأكيد :count طلبًا.', ['count' => $confirmed]);
        if ($skipped > 0) {
            $message .= ' '.__('وتُخطّي :count لتعذّر تأكيده.', ['count' => $skipped]);
        }
        if ($pendingDispatch > 0) {
            // نفس ضمان الزرّ المفرد: المكنسة المجدولة تعيد المحاولة حتى ينجح الإرسال.
            $message .= ' '.__('و:count منها سيُعاد إرساله لشركة التوصيل تلقائيًا خلال دقيقة.', ['count' => $pendingDispatch]);
        }

        return back()->with($pendingDispatch > 0 ? 'warning' : 'success', $message);
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

        if ($order->status === 'cancelled') {
            return back()->with('error', __('لا يمكن تحصيل دفعة على طلب ملغى.'));
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

    /**
     * حذف إداري نهائي لأي طلب (أيًّا كانت حالته/نوعه) مع عكس كامل لأثره المحاسبي والمخزوني.
     * متاح لحساب الأدمن فقط (سياسة forceDelete).
     */
    public function forceDestroy(Order $order, OrderVoidService $void): RedirectResponse
    {
        $this->authorize('forceDelete', $order);

        try {
            $void->void($order, request()->user());
        } catch (\Throwable $e) {
            return back()->with('error', __('تعذّر حذف الطلب: :m', ['m' => $e->getMessage()]));
        }

        return redirect()->route('admin.sales.orders.index')
            ->with('success', __('تم حذف الطلب نهائيًا وعُكس أثره المحاسبي والمخزوني.'));
    }

    /**
     * حذف جماعي للطلبات المحدَّدة — يحذف فقط ما يُسمح حذفه (ملغى + شحنته ملغاة) ولمن يملك
     * الصلاحية، ويتخطّى الباقي بصمت مع تقرير عدد المحذوف والمتخطّى.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.orders.delete'), 403);

        $ids = collect($request->input('ids', []))->filter()->map(fn ($v) => (int) $v)->all();
        if (empty($ids)) {
            return back()->with('error', __('لم تُحدَّد أي طلبات للحذف.'));
        }

        $deleted = 0;
        $skipped = 0;

        Order::with('latestShipment')->whereIn('id', $ids)->get()->each(function (Order $order) use (&$deleted, &$skipped) {
            if (self::isDeletable($order)) {
                $order->delete();
                $deleted++;
            } else {
                $skipped++;
            }
        });

        $message = trans_choice('{0}لم يُحذف أي طلب.|{1}تم حذف طلب واحد.|[2,*]تم حذف :count طلبات.', $deleted, ['count' => $deleted]);
        if ($skipped > 0) {
            $message .= ' '.__('(تُخطّي :n لعدم استيفاء شرط الحذف)', ['n' => $skipped]);
        }

        return redirect()->route('admin.sales.orders.index')->with($deleted > 0 ? 'success' : 'warning', $message);
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

    public function cancel(CancelOrderRequest $request, Order $order, OrderDeliveryDispatcher $dispatcher, OrderVoidService $void): RedirectResponse
    {
        $this->authorize('cancel', $order);

        $reason = $request->validated('reason');

        // حالات ما قبل الشحن تُلغى بتحرير الحجز فقط (لا أثر مخزوني/محاسبي بعد).
        $preShip = ['draft', 'new', 'confirmed', 'stock_reserved', 'preparing', 'ready_to_ship'];

        // طلب سبق ترحيله وخصمه من المخزون عند التقديم (حالة «مشحون» داخليًا) ولم يُسلَّم/يُلغَ:
        // الإلغاء يعكس الأثر المحاسبي والمخزوني كاملًا ويُلغي الشحنة لدى المزوّد إن وُجدت.
        if (! in_array($order->status, $preShip, true)) {
            if (in_array($order->status, ['cancelled', 'delivered', 'returned'], true)) {
                return back()->with('error', __('لا يمكن إلغاء هذا الطلب في حالته الحالية.'));
            }

            try {
                $void->cancelWithReversal($order, $request->user(), $reason);
            } catch (\Throwable $e) {
                return back()->with('error', __('تعذّر إلغاء الطلب: :m', ['m' => $e->getMessage()]));
            }

            return back()->with('success', __('أُلغي الطلب وعُكس أثره المحاسبي والمخزوني، وأُلغيت الشحنة من شركة التوصيل إن وُجدت.'));
        }

        // هل أُرسل الطلب لشركة التوصيل؟ (له معرّف خارجي أو رقم تتبّع).
        $sentToProvider = ! empty($order->delivery_external_id) || ! empty($order->tracking_number);

        try {
            $this->service->cancel($order, $reason);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // إلغاء الشحنة من شركة التوصيل (Opost) مباشرةً (متزامن) — لا اعتماد على طابور خلفية،
        // فالإلغاء ينعكس فورًا لدى المزوّد (يعالج مشكلة بقاء الشحنة نشطة بعد إلغاء الطلب).
        if ($sentToProvider && config('shipping.provider', 'null') !== 'null') {
            // عبر خدمة الإلغاء كي يُسجَّل الفشل على الطلب فتلتقطه المكنسة وتعيد المحاولة.
            $result = $void->cancelAtProvider($order);

            if (($result['status'] ?? null) === 'cancelled') {
                return back()->with('success', __('أُلغي الطلب وحُرّر الحجز، وأُلغيت الشحنة من شركة التوصيل.'));
            }

            return back()->with('warning', __('أُلغي الطلب وحُرّر الحجز، لكن **لم تُلغَ الشحنة لدى شركة التوصيل**: :msg — سيُعاد المحاولة تلقائيًا كل دقيقة، وتظهر علامة تنبيه على الطلب حتى ينجح.', ['msg' => $result['message'] ?? '']));
        }

        return back()->with('success', __('أُلغي الطلب وحُرّر الحجز.'));
    }

    /** المستودع الافتراضي (النظام أحادي المستودع حاليًا). */
    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    /** بطاقات المنتجات للبحث بالاسم مع صورة مصغّرة وسعر والكمية المتوفرة (نمط نقطة البيع). */
    private function productCards(?Warehouse $warehouse = null)
    {
        $warehouse ??= $this->defaultWarehouse();

        // الكمية المتوفرة (on_hand − reserved) لكل صنف في المستودع الافتراضي — لعرضها في نتائج البحث.
        $availableByVariant = InventoryStock::query()
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->selectRaw('variant_id, SUM(on_hand - reserved) as qty')
            ->groupBy('variant_id')
            ->pluck('qty', 'variant_id');

        // بطاقة لكل **متغيّر قابل للبيع**: المنتج ذو المقاسات/الألوان يظهر بمقاساته
        // (كمية كل مقاس على حدة)، لا ببطاقة واحدة على المتغيّر الافتراضي — فكميته
        // تُوزَّع على المقاسات وتصبح صفرًا، وخصم المخزون يجب أن يقع على المقاس المُباع.
        return Product::query()->active()
            ->with(['variants.attributeValues', 'defaultVariant', 'primaryImage'])
            ->orderBy('name')
            ->get()
            ->flatMap(function ($p) use ($availableByVariant) {
                $optionVariants = $p->variants->filter(fn ($v) => $v->attributeValues->isNotEmpty())->values();
                $hasOptions = $optionVariants->isNotEmpty();

                $sellable = $hasOptions
                    ? $optionVariants
                    : collect([$p->defaultVariant])->filter();

                return $sellable->map(fn ($v) => [
                    'name' => $hasOptions ? $p->name.' — '.$v->optionLabel() : $p->name,
                    'sku' => $v->sku ?: $p->sku,
                    'variant' => $v->uuid,
                    'price' => (float) ($v->retail_price ?: $p->retail_price),
                    'image' => $p->primaryImage?->url(),
                    'available' => (float) ($availableByVariant[$v->id] ?? 0),
                ]);
            })->values();
    }

    /**
     * أساس استعلام الطلبات المرئية للمستخدم: من يملك «العرض الكامل» يرى الجميع، وإلا فأصحاب
     * «عرض الخاص» (موظف مبيعات/مسوّق) يرون طلباتهم فقط (أنشأوها أو أُسنِدت إليهم أو مسوّقهم).
     */
    private function visibleOrders(Request $request): Builder
    {
        $query = Order::query();
        $user = $request->user();

        if ($user !== null && ! $user->can('sales.orders.view')) {
            $query->where(function ($w) use ($user) {
                $w->where('created_by', $user->id)
                    ->orWhere('assigned_to', $user->id)
                    ->orWhere('affiliate_id', $user->id);
            });
        }

        return $query;
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
