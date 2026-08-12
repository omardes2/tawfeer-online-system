<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * حذف إداري لطلب بيع مع عكس كامل لأثره المحاسبي والمخزوني (أيًّا كانت حالته/نوعه):
 *   1) إلغاء الشحنة لدى شركة التوصيل (إن أُرسلت).
 *   2) عكس سندات القبض المرتبطة (تُعيد النقد وتلغي أثر التحصيل).
 *   3) عكس قيدَي الإيراد والتكلفة بقيدين عاكسين (لا حذف — لا فجوات في ترقيم القيود).
 *   4) إعادة الكميات المُصرَّفة إلى المخزون وتحرير الحجوزات النشطة.
 *   5) عكس/إلغاء عمولات الطلب.
 *   6) حذف الطلب (حذف ناعم) بعد تصفير أثره.
 *
 * كل الخطوات محروسة (idempotent) فتعمل بأمان مهما كانت مرحلة الطلب.
 */
class OrderVoidService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly InventoryService $inventory,
        private readonly ReservationService $reservations,
        private readonly VoucherService $vouchers,
        private readonly CommissionService $commissions,
        private readonly OrderDeliveryDispatcher $dispatcher,
    ) {}

    public function void(Order $order, ?User $actor = null): void
    {
        $this->reverseEffects($order, $actor, __('حذف إداري (عكس الأثر المحاسبي)'), delete: true);
    }

    /**
     * إلغاء طلب مع عكس كامل لأثره المحاسبي والمخزوني، مع الإبقاء عليه ظاهرًا بحالة «ملغى»
     * (لا حذف). يُستخدم لإلغاء طلب سبق ترحيله/شحنه داخليًا (خُصم مخزونيًا ومحاسبيًا عند التقديم)
     * ولم يُسلَّم بعد — فيعيد الكميات للمخزون ويعكس القيود ويلغي الشحنة لدى المزوّد إن وُجدت.
     */
    public function cancelWithReversal(Order $order, ?User $actor = null, ?string $reason = null): void
    {
        $this->reverseEffects($order, $actor, $reason ?: __('إلغاء الطلب (عكس الأثر المحاسبي)'), delete: false);
    }

    private function reverseEffects(Order $order, ?User $actor, string $reason, bool $delete): void
    {
        // 1) إلغاء الشحنة لدى المزوّد — خارج المعاملة (اتصال شبكي)، أفضل جهد.
        if (! empty($order->tracking_number) || ! empty($order->delivery_external_id)) {
            try {
                $this->dispatcher->cancelShipment($order);
            } catch (\Throwable) {
                // فشل الإلغاء لدى المزوّد لا يمنع العكس المحاسبي.
            }
        }

        DB::transaction(function () use ($order, $actor, $reason, $delete) {
            $order->loadMissing('items');

            // 2) عكس سندات القبض المُرحّلة المرتبطة بالطلب (المرجع = رقم الطلب).
            FinancialVoucher::where('kind', 'receipt')
                ->where('reference', $order->number)
                ->where('status', 'posted')
                ->get()
                ->each(fn (FinancialVoucher $v) => $this->vouchers->reverse($v, __('حذف الطلب :n', ['n' => $order->number])));

            // 3) **عكس** قيدَي الإيراد والتكلفة بقيدين عاكسين (لا حذف — ADR-016): الأثر
            //    المالي صفر كما في الحذف، لكن الدفتر يحتفظ بالقيد وعكسه فلا فجوات في
            //    الترقيم ويبقى سبب تغيّر الأرصدة مقروءًا. القيد غير المُرحّل (مسودّة) يُحذف
            //    لأن أثره المالي معدوم أصلًا، والمعكوس سابقًا يُتخطّى (idempotent).
            foreach ([$order->revenue_entry_id, $order->cogs_entry_id] as $entryId) {
                if ($entryId === null) {
                    continue;
                }
                $entry = JournalEntry::find($entryId);
                if (! $entry) {
                    continue;
                }

                if (! $entry->isPosted()) {
                    $this->accounting->deleteEntry($entry);
                } elseif (! $entry->isReversed()) {
                    $this->accounting->reverse($entry, ['description' => $reason.' — '.$order->number]);
                }
            }
            // تُصفَّر المراجع كي لا يُعاد عكسها، ويبقى القيد الأصلي وعكسه في الدفتر.
            $order->update(['revenue_entry_id' => null, 'cogs_entry_id' => null]);

            // 4) إعادة الكميات المُصرَّفة للمخزون + تحرير الحجوزات النشطة.
            $warehouse = $order->warehouse;
            if ($warehouse) {
                foreach ($order->items as $item) {
                    $shipped = (float) $item->qty_shipped;
                    if ($shipped <= 0) {
                        continue;
                    }
                    $variant = ProductVariant::find($item->variant_id);
                    if (! $variant) {
                        continue;
                    }
                    $unitCost = $item->wholesale_cost_snapshot !== null
                        ? (float) $item->wholesale_cost_snapshot
                        : ($variant->average_cost !== null ? (float) $variant->average_cost : null);

                    $this->inventory->returnToStock($variant, $warehouse, $shipped, $unitCost, [
                        'reference_type' => Order::class,
                        'reference_id' => $order->id,
                        'reason' => 'void:'.$order->number,
                    ]);
                    $item->update(['qty_shipped' => 0]);
                }
            }

            StockReservation::where('order_id', $order->id)->where('status', 'active')->get()
                ->each(fn (StockReservation $r) => $this->reservations->release($r));

            // 5) عكس/إلغاء العمولات (المستحقّة تُعكس، وغير المستحقّة تُلغى).
            $this->commissions->reverseForOrder($order, $actor);
            $this->commissions->cancelForOrder($order, $actor);

            // 6) تصفير حالة الطلب (وحذفه ناعمًا عند الحذف الإداري فقط).
            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'unpaid',
                'amount_paid' => 0,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            if ($delete) {
                $order->delete();
            }
        });
    }
}
