<?php

namespace App\Modules\Shipping\Listeners;

use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Events\DeliveryStatusChanged;
use App\Modules\Shipping\Support\DeliveryStatus;
use Illuminate\Support\Facades\Log;

/**
 * يعكس إلغاء الشحنة القادم من شركة التوصيل (webhook/مزامنة) على الطلب:
 * إن ألغى المزوّد الشحنة يُلغى الطلب تلقائيًا ويُحرَّر المخزون المحجوز (إن كان الطلب قابلًا للإلغاء).
 * الإلغاء اليدوي للشحنة داخليًا لا يُلغي الطلب (يبقى القرار للمستخدم).
 */
class SyncOrderOnDeliveryStatus
{
    /** حالات الطلب التي يجوز إلغاؤها (مطابقة لـ OrderService::cancel). */
    private const CANCELLABLE = ['draft', 'new', 'confirmed', 'stock_reserved', 'preparing', 'ready_to_ship'];

    public function __construct(private readonly OrderService $orders) {}

    public function handle(DeliveryStatusChanged $event): void
    {
        // فقط إلغاء قادم من المزوّد (لا من إجراء داخلي).
        if ($event->toStatus !== DeliveryStatus::CANCELLED || $event->actorType !== 'provider') {
            return;
        }

        $order = $event->shipment->order;
        if ($order === null || ! in_array($order->status, self::CANCELLABLE, true)) {
            return; // مُلغى مسبقًا أو في حالة لا تسمح بالإلغاء (مثلًا مشحون/مُسلَّم).
        }

        try {
            $this->orders->cancel($order, __('أُلغيت الشحنة لدى شركة التوصيل.'));
        } catch (\Throwable $e) {
            Log::warning('Auto-cancel order on provider cancel failed: '.$e->getMessage(), ['order' => $order->id]);
        }
    }
}
