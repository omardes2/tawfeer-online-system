<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * الخطّ الزمني الموحّد للشحنة (Phase 4.3 / ADR-039) — يدمج (للقراءة فقط) كل المصادر
 * زمنيًا: الحالة القانونية الداخلية، حالة المزوّد، أسباب التعليق، إجراءات المستخدم،
 * أحداث الـwebhook، وأحداث المزامنة. لا يخزّن شيئًا — يبني من السجلّات القائمة.
 */
class ShipmentTimelineService
{
    /** @return Collection<int, array<string, mixed>> */
    public function build(Shipment $shipment): Collection
    {
        $shipment->loadMissing(['deliveryTransitions.actor', 'providerTransitions', 'providerEvents']);
        $items = collect();

        // 1) الحالة القانونية الداخلية (+ إجراءات المستخدم + أسباب التعليق).
        foreach ($shipment->deliveryTransitions as $t) {
            $items->push([
                'type' => 'status',
                'at' => $t->created_at,
                'actor_type' => $t->actor_type,   // user | system | provider
                'actor' => $t->actor?->name,
                'from' => $t->from_status,
                'to' => $t->to_status,
                'reason_code' => $t->reason_code, // سبب التعليق
                'note' => $t->note,
            ]);
        }

        // 2) حالة المزوّد الخام (منفصلة).
        foreach ($shipment->providerTransitions as $t) {
            $items->push([
                'type' => 'provider_status',
                'at' => $t->received_at,
                'from' => $t->from_provider_status,
                'to' => $t->to_provider_status,
                'canonical' => $t->canonical_status,
            ]);
        }

        // 3) أحداث الاستيعاب (webhook + مزامنة) — تسجيل كامل.
        foreach ($shipment->providerEvents as $e) {
            $items->push([
                'type' => $e->channel === 'sync' ? 'sync' : 'webhook',
                'at' => $e->received_at,
                'provider_status' => $e->provider_status,
                'result' => $e->status,           // processed | duplicate | failed | ...
                'signature_valid' => $e->signature_valid,
                'error' => $e->error,
            ]);
        }

        return $items
            ->sortBy(fn ($i) => optional($i['at'])->timestamp ?? 0)
            ->values();
    }
}
