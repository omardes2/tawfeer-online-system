<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Services\DeliveryWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * نقطة webhook مزوّدي التوصيل (Phase 4.3 / ADR-039). عامّة — التحقّق من التوقيع
 * وidempotency في الخدمة/الـDriver. تُرجِع 200 دائمًا (بعد التسجيل) لتفادي إعادة إرسال لا نهائية،
 * ما لم يفشل التوقيع (401). كل المنطق في DeliveryWebhookService (لا تكرار).
 */
class DeliveryWebhookController extends Controller
{
    public function __construct(private readonly DeliveryWebhookService $service) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $event = $this->service->handle(
            $provider,
            $request->getContent(),
            $request->all(),
            $this->headers($request),
        );

        $status = $event->status === 'unverified' ? 401 : 200;

        return response()->json(['status' => $event->status, 'shipment' => $event->shipment_id !== null], $status);
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $flat = [];
        foreach ($request->headers->all() as $key => $values) {
            $flat[$key] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $flat;
    }
}
