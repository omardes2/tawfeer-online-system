<?php

namespace App\Modules\Store\Services;

use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
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

        $session->fill(array_filter(
            array_intersect_key($data, array_flip([
                'customer_name', 'customer_phone', 'customer_email',
                'shipping_address', 'payment_method_code', 'notes',
            ])),
            fn ($v) => $v !== null,
        ))->save();

        return $session->refresh();
    }

    /** إتمام الجلسة: إنشاء الطلب ذرّيًا وبدء الدفع وتحويل السلة. */
    public function place(CheckoutSession $session): Order
    {
        $this->assertPending($session);

        if (! $session->isReady()) {
            throw ValidationException::withMessages(['checkout' => __('بيانات الإتمام غير مكتملة (الشحن/الدفع).')]);
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
            'channel' => 'web',
            'notes' => $session->notes,
        ];

        $items = $cart->items->map(fn ($i) => [
            'variant_id' => $i->variant_id,
            'qty' => (float) $i->qty,
            'unit_price' => (float) $i->unit_price,
        ])->all();

        $year = (int) now()->year;

        return DB::transaction(function () use ($data, $items, $year, $cart, $method) {
            // إنشاء الطلب ثم تأكيده وحجز مخزونه (ينعكس على المخزون — معيار قبول المرحلة 3).
            $order = $this->orders->create($data, $items, $year);
            $this->orders->confirm($order);
            $this->orders->reserveStock($order);

            $order->refresh();

            // رسوم التوصيل من الإعدادات (Production): افتراضي 0 إن لم تُضبط — سلوك متطابق.
            $shipping = $this->deliveryFee((float) $order->subtotal);
            if ($shipping > 0) {
                $this->orders->applyShippingTotal($order, $shipping);
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
     * رسوم التوصيل المطبَّقة عند الإتمام (Production) — من إعدادات النظام الديناميكية.
     * افتراضي 0 (غير مضبوطة). مجّانية إن بلغ المجموع الفرعي عتبة الشحن المجاني.
     */
    private function deliveryFee(float $subtotal): float
    {
        $fee = (float) Settings::get('delivery.default_fee', 0);
        if ($fee <= 0) {
            return 0.0;
        }
        $threshold = Settings::get('delivery.free_threshold');
        if ($threshold !== null && $threshold !== '' && $subtotal >= (float) $threshold) {
            return 0.0;
        }

        return round($fee, 2);
    }
}
