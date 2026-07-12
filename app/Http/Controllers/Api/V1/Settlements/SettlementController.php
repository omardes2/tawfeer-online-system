<?php

namespace App\Http\Controllers\Api\V1\Settlements;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlements\StoreSettlementRequest;
use App\Modules\Settlements\Models\DeliverySettlement;
use App\Modules\Settlements\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * التسويات المالية (Phase 4.6 / ADR-041). متحكّم رفيع — كل المنطق في SettlementService.
 * الحماية عبر can: على المسار.
 */
class SettlementController extends Controller
{
    public function __construct(private readonly SettlementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = DeliverySettlement::with('provider:id,name')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->paginate(20));
    }

    public function show(DeliverySettlement $settlement): JsonResponse
    {
        return response()->json($this->present($settlement->load(['lines.order:id,number', 'provider:id,name'])));
    }

    public function store(StoreSettlementRequest $request): JsonResponse
    {
        $settlement = $this->service->create($request->validated(), $request->user());

        return response()->json($this->present($settlement), 201);
    }

    public function addShipments(DeliverySettlement $settlement, Request $request): JsonResponse
    {
        $data = $request->validate(['shipment_ids' => ['required', 'array', 'min:1'], 'shipment_ids.*' => ['integer']]);
        $settlement = $this->service->addClosedShipments($settlement, $data['shipment_ids'], $request->user());

        return response()->json($this->present($settlement->load('lines.order:id,number')));
    }

    public function addLine(DeliverySettlement $settlement, Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'integer'],
            'shipment_id' => ['nullable', 'integer'],
            'cod_amount' => ['nullable', 'numeric'],
            'fee_amount' => ['nullable', 'numeric'],
            'deduction_amount' => ['nullable', 'numeric'],
            'reported_cod' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->addLine($settlement, $data, $request->user());

        return response()->json($this->present($settlement->fresh('lines')));
    }

    public function reconcile(DeliverySettlement $settlement, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->reconcile($settlement, $request->user())->load('lines')));
    }

    public function post(DeliverySettlement $settlement, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->post($settlement, $request->user())));
    }

    public function close(DeliverySettlement $settlement, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->close($settlement, $request->user())));
    }

    public function cancel(DeliverySettlement $settlement, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->cancel($settlement, $request->user())));
    }

    private function present(DeliverySettlement $s): array
    {
        return [
            'id' => $s->uuid,
            'number' => $s->number,
            'status' => $s->status,
            'provider' => $s->provider?->name,
            'reported' => ['cod' => $s->reported_cod_total, 'fees' => $s->reported_fees_total, 'deductions' => $s->reported_deductions_total, 'net' => $s->reported_net],
            'computed' => ['cod' => $s->computed_cod_total, 'fees' => $s->computed_fees_total, 'deductions' => $s->computed_deductions_total, 'net' => $s->computed_net],
            'variance' => $s->variance,
            'accounting_entry_id' => $s->accounting_entry_id,
            'lines' => $s->relationLoaded('lines') ? $s->lines->map(fn ($l) => [
                'order' => $l->order?->number, 'cod' => $l->cod_amount, 'fee' => $l->fee_amount,
                'deduction' => $l->deduction_amount, 'net' => $l->net_amount, 'reported_cod' => $l->reported_cod,
                'matched' => $l->matched, 'variance' => $l->variance,
            ]) : [],
        ];
    }
}
