<?php

namespace App\Modules\Returns\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Payment\Services\PaymentService;
use App\Modules\Returns\Events\ReturnCompleted;
use App\Modules\Returns\Events\ReturnRequested;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Support\ReturnWorkflow;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Services\ShipmentService;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك المرتجعات والاستبدال — RMA (Phase 4.4 / ADR-040، ADR-011، BR-RET-*).
 *
 * كل المنطق هنا (متحكّمات رفيعة). **يُعيد استخدام** الخدمات القائمة دون تكرار:
 * المخزون (InventoryService)، المدفوعات (PaymentService::refund)، العمولة
 * (CommissionService::adjust/reverse)، الطلبات (OrderService حالات المرتجع)،
 * والشحن (ShipmentService::createLinkedShipment — شحنة مرتبطة لا تعديل الأصل).
 * الأثر عكسي لا حذف (BR-RET-11)، وكل خطوة داخل معاملة ذرّية (BR-RET-12).
 */
class ReturnService
{
    /** الحالات التي يجوز إنشاء مرتجع منها (BR-RET-01). */
    private const CREATABLE_FROM = ['delivered', 'delivery_failed', 'partially_returned'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PaymentService $payments,
        private readonly CommissionService $commissions,
        private readonly OrderService $orders,
        private readonly ShipmentService $shipments,
        private readonly ReturnPostingService $posting,
    ) {}

    /**
     * إنشاء طلب مرتجع/استبدال (المرحلة: مبيعات).
     *
     * @param  array<int, array{order_item_id:int, qty:float, reason_code?:string, exchange_variant_id?:int, exchange_qty?:float, price_difference?:float}>  $items
     * @param  array{type:string, reason_code:string, resolution:string, notes?:string}  $data
     */
    public function create(Order $order, array $data, array $items, int $year, ?User $actor = null): ReturnRequest
    {
        if (! in_array($order->status, self::CREATABLE_FROM, true)) {
            throw ValidationException::withMessages(['order' => __('لا يمكن إنشاء مرتجع لطلب بحالة :s.', ['s' => $order->status])]);
        }
        $this->assertVocab($data);
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => __('حدّد بندًا واحدًا على الأقل.')]);
        }

        $order->loadMissing('items');

        return DB::transaction(function () use ($order, $data, $items, $year, $actor) {
            $request = ReturnRequest::create([
                'number' => NumberGenerator::next('return_requests', 'number', 'RMA', $year),
                'order_id' => $order->id,
                'type' => $data['type'],
                'reason_code' => $data['reason_code'],
                'resolution' => $data['resolution'],
                'status' => ReturnWorkflow::CREATED,
                'notes' => $data['notes'] ?? null,
                'requested_by' => $actor?->id ?? auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $refundBasis = 0.0;
            foreach ($items as $line) {
                $orderItem = $this->resolveOrderItem($order, (int) $line['order_item_id']);
                $qty = (float) $line['qty'];
                $this->assertReturnableQty($orderItem, $qty);

                $request->items()->create([
                    'order_item_id' => $orderItem->id,
                    'variant_id' => $orderItem->variant_id,
                    'qty' => $qty,
                    'reason_code' => $line['reason_code'] ?? $data['reason_code'],
                    'exchange_variant_id' => $line['exchange_variant_id'] ?? null,
                    'exchange_qty' => $line['exchange_qty'] ?? null,
                    'price_difference' => (float) ($line['price_difference'] ?? 0),
                    'unit_price_snapshot' => (float) $orderItem->unit_price,
                    'wholesale_cost_snapshot' => $orderItem->wholesale_cost_snapshot,
                ]);
                $refundBasis += $qty * (float) $orderItem->unit_price;
            }

            // مبلغ الاسترداد الافتراضي (يُعدَّل عند القرار النهائي) — فقط عند refund.
            $request->update([
                'scope' => $this->computeScope($order, $items),
                'refund_amount' => $data['resolution'] === 'refund' ? round($refundBasis, 2) : 0,
                'price_difference' => round(array_sum(array_map(fn ($l) => (float) ($l['price_difference'] ?? 0), $items)), 2),
            ]);

            $this->recordEvent($request, null, ReturnWorkflow::CREATED, 'sales', $actor);
            ReturnRequested::dispatch($request);

            return $request->fresh('items');
        });
    }

    /** المرحلة: مشرف المبيعات — اعتماد. */
    public function approve(ReturnRequest $request, ?User $actor = null): ReturnRequest
    {
        $this->transition($request, ReturnWorkflow::APPROVED, $actor, fn () => $request->update([
            'approved_by' => $actor?->id ?? auth()->id(), 'approved_at' => now(),
        ]));

        return $request;
    }

    public function reject(ReturnRequest $request, string $reason, ?User $actor = null): ReturnRequest
    {
        $this->transition($request, ReturnWorkflow::REJECTED, $actor, fn () => $request->update([
            'decided_by' => $actor?->id ?? auth()->id(), 'reject_reason' => $reason,
        ]), $reason);

        return $request;
    }

    public function cancel(ReturnRequest $request, ?User $actor = null): ReturnRequest
    {
        $this->transition($request, ReturnWorkflow::CANCELLED, $actor);

        return $request;
    }

    /**
     * المرحلة: المستودع — استلام. اختياريًا **شحنة مرتجع مرتبطة** إن استلزمت شركة التوصيل ذلك.
     *
     * @param  array<string, mixed>|null  $linkedShipmentData
     */
    public function receive(ReturnRequest $request, ?User $actor = null, ?array $linkedShipmentData = null): ReturnRequest
    {
        $this->transition($request, ReturnWorkflow::RECEIVED, $actor, function () use ($request, $actor, $linkedShipmentData) {
            $request->update(['received_by' => $actor?->id ?? auth()->id(), 'received_at' => now()]);

            if ($linkedShipmentData !== null) {
                $shipment = $this->shipments->createLinkedShipment(
                    $request->order, 'return_pickup', $linkedShipmentData, (int) now()->year
                );
                $request->update(['linked_shipment_id' => $shipment->id]);
            }
        });

        return $request;
    }

    /**
     * المرحلة: المستودع — الفحص والتوجيه. يطبّق حركات المخزون (return_in/damage_out) — كلّها مسجَّلة.
     *
     * @param  array<int, array{inspection_result:string, inventory_route:string}>  $inspections  مفتاحه معرّف بند المرتجع
     */
    public function inspect(ReturnRequest $request, array $inspections, ?User $actor = null): ReturnRequest
    {
        $this->transition($request, ReturnWorkflow::INSPECTED, $actor, function () use ($request, $actor, $inspections) {
            $request->loadMissing('items');
            $warehouse = $request->order->warehouse;

            foreach ($request->items as $item) {
                $decision = $inspections[$item->id] ?? null;
                if ($decision === null) {
                    throw ValidationException::withMessages(['inspection' => __('نتيجة فحص كل بند مطلوبة.')]);
                }
                $this->assertInspection($decision);

                $route = $decision['inventory_route'];
                $opts = ['reference_type' => ReturnRequest::class, 'reference_id' => $request->id, 'reason' => 'rma:'.$request->number];

                if ($route === 'restock') {
                    $this->inventory->returnToStock($item->variant, $warehouse, (float) $item->qty, $item->wholesale_cost_snapshot !== null ? (float) $item->wholesale_cost_snapshot : null, $opts);
                    $item->restocked = true;
                } elseif ($route === 'damaged') {
                    $this->inventory->returnToDamaged($item->variant, $warehouse, (float) $item->qty, $opts);
                }
                // route 'none' → لا حركة (بند مفقود/لم يعُد فعليًا).

                $item->inspection_result = $decision['inspection_result'];
                $item->inventory_route = $route;
                $item->save();
            }

            $request->update(['inspected_by' => $actor?->id ?? auth()->id(), 'inspected_at' => now()]);
        });

        return $request;
    }

    /**
     * المرحلة: قرار نهائي — إكمال. عكس العمولة، تحديث حالة الطلب، الاسترداد، والاستبدال المرتبط.
     */
    public function complete(ReturnRequest $request, ?User $actor = null): ReturnRequest
    {
        $request->loadMissing(['items', 'order.items']);
        if ($request->items->contains(fn ($i) => $i->inventory_route === null)) {
            throw ValidationException::withMessages(['inspection' => __('يجب فحص كل البنود قبل الإكمال.')]);
        }

        $this->transition($request, ReturnWorkflow::COMPLETED, $actor, function () use ($request, $actor) {
            $order = $request->order;

            // 1) تتبّع الكمية المرتجعة على مستوى البند (منع تجاوز الإرجاع).
            foreach ($request->items as $item) {
                $item->orderItem->increment('returned_qty', (float) $item->qty);
            }
            $order->load('items'); // إعادة تحميل بعد الزيادة (لا loadMissing — القيم قديمة).
            $isFull = $order->items->every(fn (OrderItem $i) => (float) $i->returned_qty + 1e-9 >= (float) $i->qty);

            // 2) عكس العمولة (BR-RET-09): كامل → reverse؛ جزئي → adjust تناسبي لكل بند.
            if ($isFull && $request->type === 'return') {
                $this->commissions->reverseForOrder($order, $actor);
            } else {
                foreach ($request->items as $item) {
                    $this->commissions->adjustForReturn($item->orderItem, (float) $item->qty, $actor);
                }
            }

            // 3) الاستبدال: صرف البديل + شحنة استبدال مرتبطة (BR-RET-07).
            if (in_array($request->type, ['exchange', 'replacement'], true)) {
                $this->issueReplacements($request);
            }

            // 4) حالة الطلب (BR-RET-10).
            $note = 'RMA:'.$request->number;
            match (true) {
                in_array($request->type, ['exchange', 'replacement'], true) => $this->orders->markExchanged($order, $note),
                $isFull => $this->orders->markReturned($order, $note),
                default => $this->orders->markPartiallyReturned($order, $note),
            };

            // 5) الترحيل المحاسبي (ADR-012f): عكس الإيراد + عكس تكلفة البضاعة المُعادة للمخزون.
            // بدونه يبقى الإيراد متضخّمًا ويقلّ حساب المخزون عن المخزون الفعلي.
            $this->posting->post($request);

            // 6) الاسترداد المالي (BR-RET-06) — إعادة استخدام PaymentService::refund.
            if ($request->resolution === 'refund' && (float) $request->refund_amount > 0) {
                $this->processRefund($request);
            }
            // store_credit/no_refund/replacement → لا استرداد نقدي هنا (رصيد دائن مؤجّل — BR-RET-08).

            $request->update(['decided_by' => $actor?->id ?? auth()->id(), 'completed_at' => now()]);
            ReturnCompleted::dispatch($request);
        });

        return $request;
    }

    /** رفع صورة دليل (اختيارية). */
    public function addPhoto(ReturnRequest $request, string $path, ?User $actor = null): void
    {
        $request->photos()->create([
            'path' => $path,
            'uploaded_by' => $actor?->id ?? auth()->id(),
            'created_at' => now(),
        ]);
    }

    // ————————————————————————————————— داخلي —————————————————————————————————

    private function issueReplacements(ReturnRequest $request): void
    {
        $warehouse = $request->order->warehouse;
        $created = false;

        foreach ($request->items as $item) {
            $variantId = $item->exchange_variant_id ?? $item->variant_id;
            $qty = (float) ($item->exchange_qty ?? $item->qty);
            if ($qty <= 0) {
                continue;
            }
            $variant = ProductVariant::findOrFail($variantId);
            // صرف البديل (sale_out بـ WAC) — حركة مسجَّلة عبر محرّك المخزون.
            $this->inventory->issue($variant, $warehouse, $qty, [
                'reference_type' => ReturnRequest::class, 'reference_id' => $request->id,
                'reason' => 'exchange:'.$request->number,
            ]);
            $created = true;
        }

        // شحنة استبدال مرتبطة (لا تعديل للأصل).
        if ($created) {
            $shipment = $this->shipments->createLinkedShipment(
                $request->order, 'exchange_delivery', [], (int) now()->year
            );
            $request->update(['linked_shipment_id' => $shipment->id]);
        }
    }

    private function processRefund(ReturnRequest $request): void
    {
        $remaining = (float) $request->refund_amount;
        $order = $request->order;

        foreach ($order->payments()->whereIn('status', ['paid', 'partially_refunded'])->get() as $payment) {
            if ($remaining <= 1e-9) {
                break;
            }
            $refundable = (float) $payment->amount - (float) $payment->refunded_amount;
            if ($refundable <= 1e-9) {
                continue;
            }
            $chunk = min($remaining, $refundable);
            $this->payments->refund($payment, $chunk);
            $request->refund_payment_id ??= $payment->id;
            $remaining -= $chunk;
        }
        $request->save();
    }

    private function resolveOrderItem(Order $order, int $orderItemId): OrderItem
    {
        $item = $order->items->firstWhere('id', $orderItemId);
        if ($item === null) {
            throw ValidationException::withMessages(['items' => __('البند لا يخصّ هذا الطلب.')]);
        }

        return $item;
    }

    private function assertReturnableQty(OrderItem $orderItem, float $qty): void
    {
        $remaining = (float) $orderItem->qty - (float) $orderItem->returned_qty;
        if ($qty <= 0 || $qty > $remaining + 1e-9) {
            throw ValidationException::withMessages([
                'qty' => __('الكمية المطلوبة تتجاوز المتبقّي القابل للإرجاع (:r).', ['r' => $remaining]),
            ]);
        }
    }

    private function computeScope(Order $order, array $items): string
    {
        $requested = [];
        foreach ($items as $l) {
            $requested[(int) $l['order_item_id']] = ($requested[(int) $l['order_item_id']] ?? 0) + (float) $l['qty'];
        }
        $full = $order->items->every(function (OrderItem $i) use ($requested) {
            $after = (float) $i->returned_qty + ($requested[$i->id] ?? 0);

            return $after + 1e-9 >= (float) $i->qty;
        });

        return $full ? 'full' : 'partial';
    }

    /** @param  array{type:string, reason_code:string, resolution:string}  $data */
    private function assertVocab(array $data): void
    {
        if (! in_array($data['type'], ReturnWorkflow::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('نوع مرتجع غير مدعوم.')]);
        }
        if (! in_array($data['reason_code'], ReturnWorkflow::REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => __('سبب غير مدعوم.')]);
        }
        if (! in_array($data['resolution'], ReturnWorkflow::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => __('طريقة تسوية غير مدعومة.')]);
        }
    }

    /** @param  array{inspection_result:string, inventory_route:string}  $decision */
    private function assertInspection(array $decision): void
    {
        if (! in_array($decision['inspection_result'], ReturnWorkflow::INSPECTION_RESULTS, true)) {
            throw ValidationException::withMessages(['inspection_result' => __('نتيجة فحص غير مدعومة.')]);
        }
        if (! in_array($decision['inventory_route'], ReturnWorkflow::INVENTORY_ROUTES, true)) {
            throw ValidationException::withMessages(['inventory_route' => __('توجيه مخزون غير مدعوم.')]);
        }
    }

    private function transition(ReturnRequest $request, string $to, ?User $actor, ?callable $effect = null, ?string $note = null): void
    {
        $from = $request->status;
        if (! ReturnWorkflow::canTransition($from, $to)) {
            throw ValidationException::withMessages(['status' => __('انتقال غير مسموح: :from → :to.', ['from' => $from, 'to' => $to])]);
        }

        DB::transaction(function () use ($request, $from, $to, $actor, $effect, $note) {
            if ($effect) {
                $effect();
            }
            $request->update(['status' => $to]);
            $this->recordEvent($request, $from, $to, ReturnWorkflow::stageFor($to), $actor, $note);
        });
    }

    private function recordEvent(ReturnRequest $request, ?string $from, string $to, ?string $stage, ?User $actor, ?string $note = null): void
    {
        $request->events()->create([
            'from_status' => $from,
            'to_status' => $to,
            'stage' => $stage,
            'actor_id' => $actor?->id ?? auth()->id(),
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
