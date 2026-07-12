<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Store\Events\CheckoutCompleted;
use App\Modules\Store\Events\CheckoutStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إتمام الشراء (ADR-033): يحوّل السلة النشطة إلى طلب مبيعات عبر الخدمات القائمة
 * (Order/Payment) داخل معاملة واحدة، ويحجز المخزون، ويبدأ الدفع، ثم يعلّم السلة محوَّلة.
 * كل المنطق تنسيقي — لا منطق أعمال جديد (يعاد استخدام 2.6/2.8/3.1). يُصدِر أحداث
 * Checkout المستقرّة (ADR-032). لا محرّك تسعير/كوبونات/ولاء (نقاط امتداد مؤجّلة).
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $input  customer_name/customer_phone/customer_email?/shipping_address/payment_method/notes?
     */
    public function checkout(User $user, array $input): Order
    {
        $cart = $this->carts->forUser($user);
        $cart->loadMissing('items.variant');

        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => __('السلة فارغة؛ لا يمكن إتمام الشراء.')]);
        }

        $branchId = $cart->branch_id ?? $user->branch_id;
        $warehouseId = Branch::whereKey($branchId)->value('default_warehouse_id');
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse' => __('لا يوجد مستودع افتراضي للفرع.')]);
        }

        // إعادة التحقّق من القابلية للبيع والتوافر (قد يتغيّر المخزون/العرض بعد الإضافة).
        foreach ($cart->items as $item) {
            if (! $item->variant) {
                throw ValidationException::withMessages(['cart' => __('أحد المنتجات لم يعد متاحًا.')]);
            }
            $this->carts->assertPurchasable($item->variant, (float) $item->qty);
        }

        $method = PaymentMethod::where('code', $input['payment_method'])->first();
        if (! $method || ! $method->is_active) {
            throw ValidationException::withMessages(['payment_method' => __('طريقة الدفع غير متاحة.')]);
        }

        $customerId = $cart->customer_id ?? Customer::where('user_id', $user->id)->value('id');

        $data = [
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'customer_id' => $customerId,
            'customer_name' => $input['customer_name'],
            'customer_phone' => $input['customer_phone'],
            'customer_email' => $input['customer_email'] ?? null,
            'shipping_address' => $input['shipping_address'],
            'channel' => 'web',
            'notes' => $input['notes'] ?? null,
        ];

        $items = $cart->items->map(fn ($i) => [
            'variant_id' => $i->variant_id,
            'qty' => (float) $i->qty,
            'unit_price' => (float) $i->unit_price,
        ])->all();

        $year = (int) now()->year;

        // نيّة إتمام الشراء (تُطلَق قبل المعاملة — تبقى إشارة حتى لو فشل الحجز/الدفع).
        CheckoutStarted::dispatch($cart, $user);

        $order = DB::transaction(function () use ($data, $items, $year, $cart, $method) {
            // إنشاء الطلب ثم تأكيده وحجز مخزونه (ينعكس على المخزون — معيار قبول المرحلة 3).
            $order = $this->orders->create($data, $items, $year);
            $this->orders->confirm($order);
            $this->orders->reserveStock($order);

            $order->refresh();
            // بدء الدفع بكامل قيمة الطلب (COD يبقى pending حتى التحصيل عند التسليم — ADR-028).
            $this->payments->initiate($order, $method, (float) $order->total, $year);

            $cart->update(['status' => 'converted']);

            return $order->fresh(['items', 'payments']);
        });

        // بعد نجاح المعاملة فقط (ADR-018): نقطة امتداد لسياق النمو.
        CheckoutCompleted::dispatch($order);

        return $order;
    }
}
