<?php

namespace App\Modules\Marketing\Listeners;

use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Returns\Events\ReturnCompleted;
use App\Modules\Returns\Events\ReturnRequested;
use App\Modules\Sales\Events\OrderDelivered;
use App\Modules\Shipping\Events\DeliveryStatusChanged;

/**
 * مستمع تحفيز حملات التسويق بالأحداث (Phase 6 / ADR-046).
 *
 * يربط أحداث الدومين القائمة بأسماء محفّزات قانونية، ويوصل عميل الحدث إلى
 * CampaignService::handleTrigger (مع مرجع idempotency). **لا منطق تسويق هنا** —
 * مجرّد جسر رفيع؛ كل الحوكمة (موافقة/حجب/تكرار) داخل MessageDispatcher.
 */
class MarketingEventSubscriber
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function onOrderDelivered(OrderDelivered $event): void
    {
        $this->trigger('order_delivered', $event->order->customer_id,
            'order_delivered:'.$event->order->id, ['order_no' => $event->order->number]);
    }

    public function onReturnRequested(ReturnRequested $event): void
    {
        $this->trigger('return_requested', $event->return->order?->customer_id,
            'return_requested:'.$event->return->id);
    }

    public function onReturnCompleted(ReturnCompleted $event): void
    {
        $this->trigger('return_completed', $event->return->order?->customer_id,
            'return_completed:'.$event->return->id);
    }

    public function onDeliveryStatusChanged(DeliveryStatusChanged $event): void
    {
        $order = $event->shipment->order;
        $this->trigger('delivery_status_changed', $order?->customer_id,
            'delivery:'.$event->shipment->id.':'.$event->toStatus,
            ['status' => $event->toStatus]);
    }

    /** @param array<string, mixed> $vars */
    private function trigger(string $event, ?int $customerId, string $reference, array $vars = []): void
    {
        if (! $customerId || ! ($customer = Customer::find($customerId))) {
            return;
        }

        $this->campaigns->handleTrigger($event, $customer, $vars, $reference);
    }

    public function subscribe(): array
    {
        return [
            OrderDelivered::class => 'onOrderDelivered',
            ReturnRequested::class => 'onReturnRequested',
            ReturnCompleted::class => 'onReturnCompleted',
            DeliveryStatusChanged::class => 'onDeliveryStatusChanged',
        ];
    }
}
