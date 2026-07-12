<?php

namespace App\Http\Controllers\Api\V1\Returns;

use App\Http\Controllers\Controller;
use App\Http\Requests\Returns\InspectReturnRequest;
use App\Http\Requests\Returns\StoreReturnRequest;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Sales\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المرتجعات/الاستبدال — RMA (Phase 4.4 / ADR-040). متحكّم رفيع — كل المنطق في ReturnService.
 * الحماية عبر can: على المسار (سير موافقات: مبيعات → مشرف → مستودع → قرار نهائي).
 */
class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ReturnRequest::with(['order:id,number'])->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->paginate(20));
    }

    public function show(ReturnRequest $return): JsonResponse
    {
        return response()->json($this->present($return->load([
            'order:id,number', 'items.orderItem', 'items.variant', 'photos', 'events.actor', 'linkedShipment:id,number,kind',
        ])));
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        $order = Order::where('uuid', $request->validated('order'))->firstOrFail();
        $return = $this->service->create($order, $request->validated(), $request->validated('items'), (int) now()->year, $request->user());

        return response()->json($this->present($return->load('items')), 201);
    }

    public function approve(ReturnRequest $return, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->approve($return, $request->user())));
    }

    public function reject(ReturnRequest $return, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->service->reject($return, $data['reason'], $request->user())));
    }

    public function receive(ReturnRequest $return, Request $request): JsonResponse
    {
        $linked = $request->boolean('create_linked_shipment') ? $request->input('shipment', []) : null;

        return response()->json($this->present($this->service->receive($return, $request->user(), $linked)));
    }

    public function inspect(InspectReturnRequest $request, ReturnRequest $return): JsonResponse
    {
        $inspections = collect($request->validated('inspections'))
            ->keyBy('item_id')
            ->map(fn ($i) => ['inspection_result' => $i['inspection_result'], 'inventory_route' => $i['inventory_route']])
            ->all();

        return response()->json($this->present($this->service->inspect($return, $inspections, $request->user())));
    }

    public function complete(ReturnRequest $return, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->complete($return, $request->user())));
    }

    public function cancel(ReturnRequest $return, Request $request): JsonResponse
    {
        return response()->json($this->present($this->service->cancel($return, $request->user())));
    }

    public function photo(ReturnRequest $return, Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120']]);
        $path = $request->file('photo')->store('returns', 'public');
        $this->service->addPhoto($return, $path, $request->user());

        return response()->json(['ok' => true, 'path' => $path], 201);
    }

    private function present(ReturnRequest $r): array
    {
        return [
            'id' => $r->uuid,
            'number' => $r->number,
            'order' => $r->order?->number,
            'type' => $r->type,
            'reason_code' => $r->reason_code,
            'resolution' => $r->resolution,
            'scope' => $r->scope,
            'status' => $r->status,
            'refund_amount' => $r->refund_amount,
            'price_difference' => $r->price_difference,
            'linked_shipment' => $r->linkedShipment?->number,
            'items' => $r->relationLoaded('items') ? $r->items->map(fn ($i) => [
                'item_id' => $i->id, 'variant_id' => $i->variant_id, 'qty' => $i->qty,
                'inspection_result' => $i->inspection_result, 'inventory_route' => $i->inventory_route,
                'restocked' => $i->restocked,
            ]) : [],
            'timeline' => $r->relationLoaded('events') ? $r->events->map(fn ($e) => [
                'from' => $e->from_status, 'to' => $e->to_status, 'stage' => $e->stage,
                'actor' => $e->actor?->name, 'note' => $e->note, 'at' => $e->created_at,
            ]) : [],
        ];
    }
}
