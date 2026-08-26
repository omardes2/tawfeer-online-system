<?php

namespace App\Modules\Store\Services;

use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Services\ShippingCostResolver;
use App\Modules\Store\Events\CheckoutCompleted;
use App\Modules\Store\Events\CheckoutStarted;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Models\CheckoutSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إتمام الشراء متعدّد الخطوات (ADR-033): تُبدأ جلسة من السلة النشطة، تتراكم عليها
 * بيانات الشحن/الدفع، ثم يُنشأ الطلب ذرّيًا في خطوة الإتمام. المعاملة النهائية
 * (إنشاء الطلب + تأكيد + حجز + بدء دفع + تحويل السلة) محفوظة كما هي من قبل. كل المنطق
 * تنسيقي — لا منطق أعمال جديد (يعاد استخدام 2.6/2.8/3.1). أحداث Checkout مستقرّة (ADR-032).
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly ShippingCostResolver $shipping,
    ) {}

    /** بدء جلسة إتمام من السلة النشطة (تُعاد الجلسة المعلّقة القائمة إن وُجدت). */
    public function start(Cart $cart): CheckoutSession
    {
        $cart->loadMissing('items');
        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => __('السلة فارغة؛ لا يمكن بدء الإتمام.')]);
        }

        $session = CheckoutSession::firstOrCreate(
            ['cart_id' => $cart->id, 'status' => 'pending'],
            [
                'user_id' => $cart->user_id,
                'session_token' => $cart->session_token,
            ],
        );

        // نيّة إتمام الشراء (نقطة امتداد لسياق النمو — هجر السلة/الرحلة).
        CheckoutStarted::dispatch($cart, $cart->user);

        return $session;
    }

    /**
     * تحديث بيانات الجلسة تدريجيًا (لقطة الشحن + طريقة الدفع).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CheckoutSession $session, array $data): CheckoutSession
    {
        $this->assertPending($session);

        $fields = array_intersect_key($data, array_flip([
            'customer_name', 'customer_phone', 'customer_email',
            'shipping_address', 'city_id', 'area_id',
            'payment_method_code', 'notes',
        ]));

        // «لا منطقة» تصل من القائمة نصًّا فارغًا لا null، فتُسنَد إلى مفتاح أجنبي.
        // تُطبَّع هنا لا في الواجهة: الخلفية هي التي تضمن سلامة البيانات.
        foreach (['city_id', 'area_id'] as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] === '') {
                $fields[$key] = null;
            }
        }

        $session->fill(array_filter($fields, fn ($v) => $v !== null))->save();

        return $session->refresh();
    }

    /** إتمام الجلسة: إنشاء الطلب ذرّيًا وبدء الدفع وتحويل السلة. */
    public function place(CheckoutSession $session): Order
    {
        $this->assertPending($session);

        if (! $session->isReady()) {
            throw ValidationException::withMessages(['checkout' => __('بيانات الإتمام غير مكتملة (الشحن/الدفع).')]);
        }

        // المدينة شرط **متى كانت مدن التوصيل مُعدّة**: بدونها لا رسوم صحيحة ولا
        // حمولة صالحة لشركة التوصيل. أمّا متجر لم تُضبط فيه مدن بعد فلا يُمنع
        // من البيع — يعود إلى الرسم الافتراضي كما كان.
        if ($session->city_id === null && DeliveryCityRate::where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['city_id' => __('اختر المدينة لاحتساب رسوم التوصيل.')]);
        }

        $cart = $session->cart()->with('items.variant')->first();
        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => __('السلة فارغة؛ لا يمكن الإتمام.')]);
        }

        // إعادة التحقّق من القابلية للبيع والتوافر (قد يتغيّر المخزون/العرض بعد الإضافة).
        foreach ($cart->items as $item) {
            if (! $item->variant) {
                throw ValidationException::withMessages(['cart' => __('أحد المنتجات لم يعد متاحًا.')]);
            }
            $this->carts->assertPurchasable($item->variant, (float) $item->qty);
        }

        $method = PaymentMethod::where('code', $session->payment_method_code)->first();
        if (! $method || ! $method->is_active) {
            throw ValidationException::withMessages(['payment_method' => __('طريقة الدفع غير متاحة.')]);
        }

        $order = $this->placeOrder($cart, $session, $method);

        $session->update(['status' => 'placed', 'order_id' => $order->id]);

        // بعد نجاح المعاملة فقط (ADR-018): نقطة امتداد لسياق النمو.
        CheckoutCompleted::dispatch($order);

        return $order;
    }

    /**
     * المعاملة الذرّية النهائية (محفوظة كما هي — ADR-033): إنشاء الطلب + تأكيد + حجز
     * مخزون + بدء دفع + تحويل السلة. Order + Payment كما نُفّذا سابقًا تمامًا.
     */
    private function placeOrder(Cart $cart, CheckoutSession $session, PaymentMethod $method): Order
    {
        $branchId = $cart->branch_id ?? Branch::default()?->id;
        $warehouseId = Branch::whereKey($branchId)->value('default_warehouse_id');
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse' => __('لا يوجد مستودع افتراضي للفرع.')]);
        }

        $data = [
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'customer_id' => $cart->customer_id,
            'customer_name' => $session->customer_name,
            'customer_phone' => $session->customer_phone,
            'customer_email' => $session->customer_email,
            'shipping_address' => $session->shipping_address,
            'city_id' => $session->city_id,
            'area_id' => $session->area_id,
            'channel' => 'web',
            'notes' => $session->notes,
        ];

        $items = $cart->items->map(fn ($i) => [
            'variant_id' => $i->variant_id,
            'qty' => (float) $i->qty,
            'unit_price' => (float) $i->unit_price,
        ])->all();

        $year = (int) now()->year;

        $deliveryFee = $this->deliveryFee($session, (float) $cart->subtotal());

        return DB::transaction(function () use ($data, $items, $year, $cart, $method, $deliveryFee) {
            // إنشاء الطلب ثم تأكيده وحجز مخزونه (ينعكس على المخزون — معيار قبول المرحلة 3).
            $order = $this->orders->create($data, $items, $year);
            $this->orders->confirm($order);
            $this->orders->reserveStock($order);

            $order->refresh();

            if ($deliveryFee > 0) {
                $this->orders->applyShippingTotal($order, $deliveryFee);
                $order->refresh();
            }

            // بدء الدفع بكامل قيمة الطلب (COD يبقى pending حتى التحصيل عند التسليم — ADR-028).
            $this->payments->initiate($order, $method, (float) $order->total, $year);

            $cart->update(['status' => 'converted']);

            return $order->fresh(['items', 'payments']);
        });
    }

    private function assertPending(CheckoutSession $session): void
    {
        if ($session->status !== 'pending') {
            throw ValidationException::withMessages(['checkout' => __('جلسة الإتمام لم تعد قابلة للتعديل.')]);
        }
    }

    /**
     * رسوم التوصيل لجلسة الإتمام — **من الخلفية دائمًا**، عبر مُحلّل التكلفة القائم
     * (`ShippingCostResolver`) الذي يقرأ سعر المدينة من طبقة التكامل. لا تُكرَّر
     * معادلة التسعير هنا ولا في الواجهة: هذه هي الدالة الوحيدة التي تحسبها،
     * وتستدعيها الواجهة عبر الجلسة فتعرض ما تُرجعه الخلفية لا ما تحسبه بنفسها.
     *
     * الاحتياط: إن لم يوجد سعر للمدينة يُستخدم الرسم الافتراضي من الإعدادات
     * (السلوك السابق) بدل صفر — كي لا يمرّ طلب برسوم توصيل مفقودة.
     * وتبقى مجّانية إن بلغ المجموع الفرعي عتبة الشحن المجاني.
     */
    public function deliveryFee(CheckoutSession $session, float $subtotal): float
    {
        $threshold = Settings::get('delivery.free_threshold');
        if ($threshold !== null && $threshold !== '' && $subtotal >= (float) $threshold) {
            return 0.0;
        }

        $fee = 0.0;

        if ($session->city_id !== null) {
            // (1) عرض المزوّد عبر طبقة التكامل (المبدأ 13) — يعمل حين يكون مزوّد
            //     شحن حيّ مضبوطًا، ويبقى المتجر غير عالم بهويّته.
            $fee = (float) $this->shipping->resolve([
                'city_id' => $session->city_id,
                'area_id' => $session->area_id,
            ])->cost;

            // (2) جدول أسعار المدن في اللوحة — نفس مصدر طلبات لوحة الإدارة.
            //     ضروري لأن مُحلّل التكلفة يصمت حين يكون المزوّد `null`، فكان
            //     طلب الويب يخرج بلا رسوم بينما الطلب اليدوي لنفس المدينة يحملها.
            if ($fee <= 0) {
                // `customerFee()` لا `delivery_fee`: الأوّل سعرُ البيع إن ضُبط
                // للمدينة، والثاني تكلفتُها لدى شركة التوصيل. والزبون يدفع
                // الأوّل. ومن لم يُضبط له سعرُ بيعٍ يبقى على التكلفة كما كان.
                $fee = (float) (DeliveryCityRate::where('is_active', true)
                    ->where('city_id', $session->city_id)
                    ->first()?->customerFee() ?? 0);
            }
        }

        // (3) الرسم الافتراضي من الإعدادات (السلوك السابق) — آخر احتياط.
        if ($fee <= 0) {
            $fee = (float) Settings::get('delivery.default_fee', 0);
        }

        return $fee > 0 ? round($fee, 2) : 0.0;
    }
}
