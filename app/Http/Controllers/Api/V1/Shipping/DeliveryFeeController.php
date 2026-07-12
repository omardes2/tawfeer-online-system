<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\FeeComponentRequest;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryFeeService;
use Illuminate\Http\JsonResponse;

/**
 * رسوم التوصيل المُركّبة (Phase 4.3 / ADR-039) — مستقلّة عن المزوّد. متحكّم رفيع.
 */
class DeliveryFeeController extends Controller
{
    public function __construct(private readonly DeliveryFeeService $service) {}

    public function index(Shipment $shipment): JsonResponse
    {
        return response()->json([
            'components' => $this->service->components($shipment)->map(fn ($c) => [
                'type' => $c->type, 'amount' => $c->amount, 'owner' => $c->owner, 'source' => $c->source, 'note' => $c->note,
            ]),
            'total' => $this->service->total($shipment),
            'totals_by_owner' => $this->service->totalsByOwner($shipment),
        ]);
    }

    public function store(FeeComponentRequest $request, Shipment $shipment): JsonResponse
    {
        $this->service->addComponent($shipment, $request->validated('type'), (float) $request->validated('amount'), [
            'owner' => $request->validated('owner'),
            'source' => $request->validated('source'),
            'note' => $request->validated('note'),
            'actor' => $request->user(),
        ]);

        return response()->json(['total' => $this->service->total($shipment)], 201);
    }
}
