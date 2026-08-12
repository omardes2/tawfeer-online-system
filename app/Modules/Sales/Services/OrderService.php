<?php

namespace App\Modules\Sales\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Sales\Events\OrderDelivered;
use App\Modules\Sales\Models\Order;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * طلبات البيع (ADR-009/010/026، BR-ORD-*). آلة حالات؛ المخزون حصريًا عبر خدمات المخزون.
 * draft → confirmed → stock_reserved → preparing → ready_to_ship → shipped → delivered (+cancelled).
 */
class OrderService
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly InventoryService $inventory,
        private readonly SalesPostingService $posting,
    ) {}

    /**
     * @param  array<int, array{variant_id:int, qty:float, unit_price:float, discount?:float}>  $items
     */
    public function create(array $data, array $items, int $year): Order
    {
        // عميل محظور لا يُنشئ طلبات جديدة (BR-CUST-12) — عند ربط عميل.
        if (! empty($data['customer_id'])) {
            $blocked = Customer::whereKey($data['customer_id'])->value('is_blocked');
            if ($blocked) {
                throw ValidationException::withMessages(['customer' => __('العميل محظور ولا يمكن إنشاء طلبات له.')]);
            }
        }

        // يُمنع البيع بأقل من سعر الجملة لكل الأصناف ولجميع المستخدمين (كل قنوات البيع).
        $this->assertPricesAboveWholesale($items);

        return DB::transaction(function () use ($data, $items, $year) {
            $order = Order::create([
                'number' => NumberGenerator::next('orders', 'number', 'SO', $year),
                'branch_id' => $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'area_id' => $data['area_id'] ?? null,
                'has_return' => $data['has_return'] ?? false,
                'return_notes' => $data['return_notes'] ?? null,
                'shipping_total' => $data['shipping_total'] ?? 0,
                'channel' => $data['channel'] ?? 'manual',
                'status' => 'draft',
                'assigned_to' => $data['assigned_to'] ?? null,
                'affiliate_id' => $data['affiliate_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncItems($order, $items);
            $this->recomputeTotals($order);
            $this->recordHistory($order, null, 'draft', $data['notes'] ?? null);

            return $order;
        });
    }

    public function update(Order $order, array $data, ?array $items = null): Order
    {
        // قابل للتعديل قبل الحجز فقط (BR-ORD-07).
        $this->assertStatus($order, ['draft', 'new']);

        return DB::transaction(function () use ($order, $data, $items) {
            $order->update(array_intersect_key($data, array_flip([
                'warehouse_id', 'customer_id', 'customer_name', 'customer_phone',
                'customer_email', 'shipping_address', 'channel', 'assigned_to', 'notes',
            ])));

            if ($items !== null) {
                $this->assertPricesAboveWholesale($items);
                $order->items()->delete();
                $this->syncItems($order, $items);
                $this->recomputeTotals($order);
            }

            return $order;
        });
    }

    /**
     * تعديل فاتورة/طلب قائم (بيانات التواصل + الأصناف/الكميات/الأسعار) مع مزامنة الأثر:
     *  - إن كان الطلب قد رُحّل وخُصم مخزونيًا (revenue_entry_id مضبوط): تُعاد كميات البنود القديمة
     *    للمخزون، تُستبدل البنود، تُصرَف كميات البنود الجديدة، ويُحدَّث القيد المحاسبي في مكانه
     *    (لا قيد جديد) عبر SalesPostingService::repost.
     *  - إن لم يُرحّل بعد (مسودّة): تُستبدل البنود ويُعاد احتساب الإجمالي فقط (لا مخزون/قيد).
     *
     * @param  array<int, array{variant_id:int, qty:float, unit_price:float, discount?:float}>  $items
     */
    public function editPostedOrder(Order $order, array $data, array $items): Order
    {
        $this->assertPricesAboveWholesale($items);

        return DB::transaction(function () use ($order, $data, $items) {
            $order->loadMissing('items');
            $warehouse = $order->warehouse;
            $wasFulfilled = $order->revenue_entry_id !== null;

            // 1) إعادة كميات البنود القديمة المصروفة للمخزون (فقط إن كان الطلب مُرحّلًا/مشحونًا).
            if ($wasFulfilled && $warehouse) {
                foreach ($order->items as $item) {
                    $shipped = (float) $item->qty_shipped;
                    if ($shipped <= 0 || ! $item->variant_id) {
                        continue;
                    }
                    $variant = ProductVariant::find($item->variant_id);
                    if ($variant) {
                        $this->inventory->returnToStock($variant, $warehouse, $shipped,
                            $variant->average_cost !== null ? (float) $variant->average_cost : null, [
                                'reference_type' => Order::class,
                                'reference_id' => $order->id,
                                'reason' => 'edit_return:'.$order->number,
                            ]);
                    }
                }
            }

            // 2) استبدال البنود بالجديدة وإعادة احتساب الإجمالي.
            $order->items()->delete();
            $this->syncItems($order, $items);
            $order->update(array_intersect_key($data, array_flip([
                'customer_name', 'customer_phone', 'customer_email', 'shipping_address', 'notes',
            ])));
            $order->unsetRelation('items'); // تفريغ البنود القديمة المُحمَّلة حتى يُعاد الاحتساب على الجديدة.
            $this->recomputeTotals($order);

            // 3) صرف كميات البنود الجديدة من المخزون + تحديث القيد في مكانه (للطلب المُرحّل فقط).
            if ($wasFulfilled) {
                $order->loadMissing('items');
                if ($warehouse) {
                    foreach ($order->items as $item) {
                        $qty = (float) $item->qty;
                        if ($qty <= 0 || ! $item->variant_id) {
                            continue;
                        }
                        $variant = ProductVariant::find($item->variant_id);
                        if ($variant) {
                            $this->inventory->issue($variant, $warehouse, $qty, [
                                'reference_type' => Order::class,
                                'reference_id' => $order->id,
                                'reason' => 'edit_sale:'.$order->number,
                            ]);
                        }
                        $item->update(['qty_reserved' => $qty, 'qty_shipped' => $qty]);
                    }
                }

                $this->posting->repost($order->fresh(['items', 'warehouse', 'customer']));
            }

            return $order->fresh();
        });
    }

    /**
     * يمنع البيع بأقل من سعر الجملة: صافي سعر الوحدة (بعد الخصم) يجب ألّا يقلّ عن
     * wholesale_price للمتغيّر عندما يكون محدَّدًا (> 0). يُطبَّق على كل قنوات البيع ولكل المستخدمين.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertPricesAboveWholesale(array $items): void
    {
        $variantIds = collect($items)->pluck('variant_id')->filter()->unique()->all();
        if (empty($variantIds)) {
            return;
        }

        $variants = ProductVariant::with('product:id,name')->whereIn('id', $variantIds)->get()->keyBy('id');

        foreach ($items as $item) {
            $variant = $variants->get($item['variant_id'] ?? null);
            if (! $variant) {
                continue;
            }
            $wholesale = (float) ($variant->wholesale_price ?? 0);
            if ($wholesale <= 0) {
                continue; // لا سعر جملة محدَّد ⇒ لا قيد.
            }
            $qty = max((float) ($item['qty'] ?? 1), 0.0001);
            $netUnit = (float) ($item['unit_price'] ?? 0) - ((float) ($item['discount'] ?? 0) / $qty);
            if ($netUnit + 0.001 < $wholesale) {
                throw ValidationException::withMessages([
                    'items' => __('لا يمكن بيع «:name» بأقل من سعر الجملة (:price).', [
                        'name' => $variant->product?->name ?? $variant->sku ?? __('صنف'),
                        'price' => number_format($wholesale, 2),
                    ]),
                ]);
            }
        }
    }

    public function delete(Order $order): void
    {
        // حذف ناعم قبل الحجز فقط (BR-ORD-02/07).
        $this->assertStatus($order, ['draft', 'new']);
        $order->delete();
    }

    public function confirm(Order $order): Order
    {
        $this->transition($order, ['draft', 'new'], 'confirmed', fn () => $order->update(['confirmed_at' => now()]));

        // ترحيل محاسبي تلقائي عند التأكيد: قيدا الإيراد والتكلفة (Posting Setup).
        $this->posting->post($order);

        return $order;
    }

    /**
     * بيع مباشر من المستودع (بلا شركة توصيل): يمرّ بالطلب فورًا حتى «مُسلَّم»
     * فيخصم المخزون (sale_out/COGS) ويُطلق استحقاق العمولة — كل ذلك في معاملة واحدة.
     */
    public function fulfillDirect(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->confirm($order);
            $this->reserveStock($order);
            $this->startPreparing($order);
            $this->markReady($order);
            $this->ship($order);
            $this->deliver($order);

            return $order->fresh();
        });
    }

    /**
     * معالجة الطلب حتى «الشحن» فقط: ترحيل محاسبي (عند التأكيد) وخصم الكميات من المخزون
     * (عند الشحن)، دون «تسليم» ودون إرسال لشركة التوصيل — يبقى إرسال التوصيل خطوة لاحقة
     * (تأكيد الأدمن). يُستخدم عند «تقديم الطلب» ليُحتسب البيع مخزونيًا ومحاسبيًا فورًا.
     */
    public function fulfillToShipped(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->confirm($order);
            $this->reserveStock($order);
            $this->startPreparing($order);
            $this->markReady($order);
            $this->ship($order);

            return $order->fresh();
        });
    }

    /** التأكيد → الحجز: يحجز كل بند عبر ReservationService (ADR-009، BR-ORD-06). */
    public function reserveStock(Order $order): Order
    {
        $this->transition($order, ['confirmed'], 'stock_reserved', function () use ($order) {
            $order->loadMissing('items');
            $warehouse = $order->warehouse;

            foreach ($order->items as $item) {
                $qty = (float) $item->qty;
                if ($qty <= 0) {
                    continue;
                }

                $variant = ProductVariant::findOrFail($item->variant_id);
                $reservation = $this->reservations->reserve($variant, $warehouse, $qty, [
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                ]);
                // ربط الحجز بالطلب/البند عبر الأعمدة المخصّصة (كانت مؤجّلة — تُستخدم الآن).
                $reservation->update(['order_id' => $order->id, 'order_item_id' => $item->id]);

                $item->update(['qty_reserved' => $qty]);
            }

            $order->update(['reserved_at' => now()]);
        });

        return $order;
    }

    public function startPreparing(Order $order): Order
    {
        $this->transition($order, ['stock_reserved'], 'preparing');

        return $order;
    }

    public function markReady(Order $order): Order
    {
        $this->transition($order, ['preparing'], 'ready_to_ship');

        return $order;
    }

    /** الشحن: استهلاك الحجز (reserved−=qty) ثم خصم on_hand (sale_out/COGS بـ WAC) — ADR-009. */
    public function ship(Order $order): Order
    {
        $this->transition($order, ['ready_to_ship'], 'shipped', function () use ($order) {
            $order->loadMissing('items');
            $warehouse = $order->warehouse;

            foreach ($order->items as $item) {
                $qty = (float) $item->qty;
                if ($qty <= 0) {
                    continue;
                }

                $variant = ProductVariant::findOrFail($item->variant_id);

                $reservation = StockReservation::where('order_id', $order->id)
                    ->where('order_item_id', $item->id)
                    ->where('status', 'active')->first();
                if ($reservation) {
                    $this->reservations->consume($reservation);
                }

                $this->inventory->issue($variant, $warehouse, $qty, [
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'reason' => 'sale:'.$order->number,
                ]);

                $item->update(['qty_shipped' => $qty]);
            }

            $order->update(['shipped_at' => now()]);
        });

        return $order;
    }

    /**
     * الحالات التشغيلية للتوصيل (BR-ORD-10، ADR-010) — تُقاد من وحدة الشحن (Phase 2.7).
     * كانت معرّفة في 2.6 ومؤجّلة المسارات؛ هنا تُفتح دون تغيير الأثر المالي/المخزوني.
     */
    public function markOutForDelivery(Order $order): Order
    {
        $this->transition($order, ['shipped', 'delayed', 'customer_unavailable'], 'out_for_delivery');

        return $order;
    }

    public function markDelayed(Order $order, ?string $note = null): Order
    {
        $this->transition($order, ['shipped', 'out_for_delivery'], 'delayed', null, $note);

        return $order;
    }

    public function markCustomerUnavailable(Order $order, ?string $note = null): Order
    {
        $this->transition($order, ['shipped', 'out_for_delivery'], 'customer_unavailable', null, $note);

        return $order;
    }

    public function markDeliveryFailed(Order $order, ?string $reason = null): Order
    {
        $this->transition($order, ['shipped', 'out_for_delivery', 'delayed', 'customer_unavailable'], 'delivery_failed', null, $reason);

        return $order;
    }

    /** التسليم: اعتراف الإيراد يُنفَّذ محاسبيًا في 2.9؛ هنا معلم زمني فقط (ADR-010a). */
    public function deliver(Order $order): Order
    {
        $this->transition(
            $order,
            ['shipped', 'out_for_delivery', 'delayed', 'customer_unavailable'],
            'delivered',
            fn () => $order->update(['delivered_at' => now()]),
        );

        // استحقاق العمولة (pending) — Phase 4.2. الاستحقاق النهائي (eligible) عند التسوية (4.6).
        OrderDelivered::dispatch($order);

        return $order;
    }

    /** الإلغاء قبل الشحن: يحرّر الحجوزات النشطة (BR-ORD-11). يلزم سبب. */
    public function cancel(Order $order, string $reason): Order
    {
        $allowed = ['draft', 'new', 'confirmed', 'stock_reserved', 'preparing', 'ready_to_ship'];

        $this->transition($order, $allowed, 'cancelled', function () use ($order, $reason) {
            StockReservation::where('order_id', $order->id)->where('status', 'active')->get()
                ->each(fn ($r) => $this->reservations->release($r));

            $order->update(['cancel_reason' => $reason, 'cancelled_at' => now()]);
        }, $reason);

        return $order;
    }

    /** إرجاع كامل بعد التسليم (BR-RET-10) — تُستدعى من وحدة المرتجعات (RMA). */
    public function markReturned(Order $order, ?string $note = null): Order
    {
        $this->transition($order, ['delivered', 'delivery_failed', 'partially_returned'], 'returned', null, $note);

        return $order;
    }

    /** إرجاع جزئي بعد التسليم (BR-RET-10) — يبقى الطلب قائمًا للبنود المتبقّية. */
    public function markPartiallyReturned(Order $order, ?string $note = null): Order
    {
        $this->transition($order, ['delivered', 'partially_returned'], 'partially_returned', null, $note);

        return $order;
    }

    /** استبدال بعد التسليم (BR-RET-07/10). */
    public function markExchanged(Order $order, ?string $note = null): Order
    {
        $this->transition($order, ['delivered', 'partially_returned'], 'exchanged', null, $note);

        return $order;
    }

    /** لقطة تكلفة الشحن على الطلب وإعادة احتساب الإجمالي (المتطلّب 8 — تُستدعى من وحدة الشحن). */
    public function applyShippingTotal(Order $order, float $shippingTotal): Order
    {
        $order->update([
            'shipping_total' => $shippingTotal,
            'total' => (float) $order->subtotal - (float) $order->discount_total + (float) $order->tax_total + $shippingTotal,
        ]);

        return $order;
    }

    // ————————————————————————————————— داخلي —————————————————————————————————

    private function syncItems(Order $order, array $items): void
    {
        // لقطة تكلفة الجملة وقت البيع (BR-ORD-18): مرجع ثابت للربح والتكلفة والعمولة —
        // تُجمَّد هنا فلا تتغيّر بتغيّر WAC لاحقًا. بدونها تُحتسب التكلفة صفرًا في التقارير.
        $costs = ProductVariant::whereIn('id', array_column($items, 'variant_id'))
            ->pluck('average_cost', 'id');

        foreach ($items as $item) {
            $qty = (float) $item['qty'];
            $unitPrice = (float) $item['unit_price'];
            $discount = (float) ($item['discount'] ?? 0);

            $order->items()->create([
                'variant_id' => $item['variant_id'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'line_total' => ($qty * $unitPrice) - $discount,
                'wholesale_cost_snapshot' => round((float) ($item['wholesale_cost_snapshot'] ?? $costs[$item['variant_id']] ?? 0), 2),
                'qty_reserved' => 0,
                'qty_shipped' => 0,
            ]);
        }
    }

    private function recomputeTotals(Order $order): void
    {
        $order->loadMissing('items');
        $subtotal = $order->items->sum(fn ($i) => (float) $i->qty * (float) $i->unit_price);
        $discount = $order->items->sum(fn ($i) => (float) $i->discount);
        $tax = $order->items->sum(fn ($i) => (float) $i->tax_amount);

        $order->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'total' => $subtotal - $discount + $tax + (float) $order->shipping_total,
        ]);
    }

    /**
     * انتقال حالة قانوني داخل معاملة مع تسجيل السجلّ (BR-ORD-09، ADR-017).
     */
    private function transition(Order $order, array $from, string $to, ?callable $effect = null, ?string $note = null): void
    {
        $this->assertStatus($order, $from);

        DB::transaction(function () use ($order, $to, $effect, $note) {
            $fromStatus = $order->status;

            if ($effect) {
                $effect();
            }

            $order->update(['status' => $to]);
            $this->recordHistory($order, $fromStatus, $to, $note);
        });
    }

    private function recordHistory(Order $order, ?string $from, string $to, ?string $note): void
    {
        $order->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'changed_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    private function assertStatus(Order $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('العملية غير مسموحة على طلب بحالة :status.', ['status' => $order->status]),
            ]);
        }
    }
}
