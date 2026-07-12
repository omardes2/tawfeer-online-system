<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\OpenExceptionRequest;
use App\Models\User;
use App\Modules\Shipping\Models\DeliveryException;
use App\Modules\Shipping\Models\DeliveryExceptionCategory;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * استثناءات التوصيل (Phase 4.3 / ADR-039). متحكّم رفيع — كل المنطق في الخدمة.
 */
class DeliveryExceptionController extends Controller
{
    public function __construct(private readonly DeliveryExceptionService $service) {}

    public function index(Shipment $shipment): JsonResponse
    {
        return response()->json([
            'exceptions' => $shipment->exceptions()->with(['category', 'assignee', 'notes.author'])->get()->map(fn ($e) => $this->present($e)),
        ]);
    }

    public function store(OpenExceptionRequest $request, Shipment $shipment): JsonResponse
    {
        $category = DeliveryExceptionCategory::where('code', $request->validated('category'))->firstOrFail();
        $exception = $this->service->open($shipment, $category, [
            'assigned_to' => $request->validated('assigned_to'),
            'note' => $request->validated('note'),
            'actor' => $request->user(),
        ]);

        return response()->json($this->present($exception->load(['category', 'notes.author'])), 201);
    }

    public function note(Request $request, DeliveryException $exception): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $this->service->addNote($exception, $data['note'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, DeliveryException $exception): JsonResponse
    {
        $data = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);
        $this->service->assign($exception, User::findOrFail($data['assigned_to']), $request->user());

        return response()->json($this->present($exception->fresh(['category'])));
    }

    public function escalate(Request $request, DeliveryException $exception): JsonResponse
    {
        $this->service->escalate($exception, $request->user(), $request->input('note'));

        return response()->json($this->present($exception->fresh(['category'])));
    }

    public function resolve(Request $request, DeliveryException $exception): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'max:500']]);
        $this->service->resolve($exception, $data['resolution'], $request->user());

        return response()->json($this->present($exception->fresh(['category'])));
    }

    public function reopen(Request $request, DeliveryException $exception): JsonResponse
    {
        $this->service->reopen($exception, $request->user(), $request->input('note'));

        return response()->json($this->present($exception->fresh(['category'])));
    }

    private function present(DeliveryException $e): array
    {
        return [
            'id' => $e->uuid,
            'category' => $e->category?->code,
            'status' => $e->status,
            'assigned_to' => $e->assignee?->name,
            'sla_due_at' => $e->sla_due_at,
            'escalated_at' => $e->escalated_at,
            'resolved_at' => $e->resolved_at,
            'resolution' => $e->resolution,
            'reopened_count' => $e->reopened_count,
            'notes' => $e->relationLoaded('notes') ? $e->notes->map(fn ($n) => [
                'note' => $n->note, 'kind' => $n->kind, 'author' => $n->author?->name, 'at' => $n->created_at,
            ]) : [],
        ];
    }
}
