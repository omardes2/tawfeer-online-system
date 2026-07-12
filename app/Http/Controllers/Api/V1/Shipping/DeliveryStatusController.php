<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\DeliveryTransitionRequest;
use App\Http\Requests\Shipping\ProviderStatusRequest;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryStatusService;
use App\Modules\Shipping\Support\DeliveryStatus;
use Illuminate\Http\JsonResponse;

/**
 * محرّك حالة التوصيل القانوني (Phase 4.3 / ADR-038). متحكّم رفيع — كل المنطق في الخدمة.
 * الحماية عبر can: على المسار.
 */
class DeliveryStatusController extends Controller
{
    public function __construct(private readonly DeliveryStatusService $service) {}

    /** الحالة القانونية الحالية + سجلّ الانتقالات (قانوني + مزوّد خام، منفصلان). */
    public function show(Shipment $shipment): JsonResponse
    {
        $shipment->load(['deliveryTransitions.actor', 'providerTransitions']);

        return response()->json([
            'shipment' => $shipment->number,
            'delivery_status' => $shipment->delivery_status,
            'provider_status' => $shipment->provider_status,
            'on_hold_reason' => $shipment->on_hold_reason,
            'closed_at' => $shipment->closed_at,
            'allowed_transitions' => DeliveryStatus::TRANSITIONS[$shipment->delivery_status] ?? [],
            'canonical_history' => $shipment->deliveryTransitions->map(fn ($t) => [
                'from' => $t->from_status, 'to' => $t->to_status,
                'actor_type' => $t->actor_type, 'actor' => $t->actor?->name,
                'reason_code' => $t->reason_code, 'note' => $t->note,
                'at' => $t->created_at,
            ]),
            'provider_history' => $shipment->providerTransitions->map(fn ($t) => [
                'from' => $t->from_provider_status, 'to' => $t->to_provider_status,
                'canonical' => $t->canonical_status, 'event_id' => $t->event_id,
                'at' => $t->received_at,
            ]),
        ]);
    }

    /** انتقال قانوني يدوي (submit/pickup/hold/return...). CLOSE له مسار مستقل. */
    public function transition(DeliveryTransitionRequest $request, Shipment $shipment): JsonResponse
    {
        $to = $request->validated('to');
        abort_if($to === DeliveryStatus::CLOSED, 403, __('الإغلاق المالي عبر مسار مستقل.'));

        $shipment = $this->service->transitionTo($shipment, $to, [
            'source' => 'user',
            'actor' => $request->user(),
            'reason' => $request->validated('reason'),
            'note' => $request->validated('note'),
        ]);

        return response()->json(['delivery_status' => $shipment->delivery_status, 'on_hold_reason' => $shipment->on_hold_reason]);
    }

    /** الإغلاق المالي النهائي (CLOSE) — يُفعّل التسوية واستحقاق العمولات (لا دفع تلقائي). */
    public function close(Shipment $shipment): JsonResponse
    {
        $shipment = $this->service->close($shipment, request()->user());

        return response()->json([
            'delivery_status' => $shipment->delivery_status,
            'closed_at' => $shipment->closed_at,
            'order_settled_at' => $shipment->order?->fresh()->settled_at,
        ]);
    }

    /** استيعاب حالة مزوّد خام (webhook/مزامنة) — idempotent، التعيين في الـDriver. */
    public function providerStatus(ProviderStatusRequest $request, Shipment $shipment): JsonResponse
    {
        $shipment = $this->service->applyProviderStatus($shipment, $request->validated('provider_status'), [
            'event_id' => $request->validated('event_id'),
            'payload' => $request->validated('payload'),
            'actor' => $request->user(),
        ]);

        return response()->json([
            'provider_status' => $shipment->provider_status,
            'delivery_status' => $shipment->delivery_status,
        ]);
    }

    /** تقرير أسباب التعليق المُصنّفة (قابلة للتقرير — المتطلّب 3). */
    public function holdReasons(): JsonResponse
    {
        return response()->json(['reasons' => $this->service->holdReasonReport()]);
    }
}
