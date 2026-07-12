<?php

namespace App\Http\Controllers\Api\V1\Commissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commissions\PayoutRequest;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Services\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * دفتر العمولات/الأرباح (Phase 4.2): عرض/كشف حساب/اعتماد دفعة/صرف دفعة. متحكّم رفيع.
 * النطاق: view_own يرى بنوده فقط؛ view_team يرى الكل.
 */
class CommissionController extends Controller
{
    public function __construct(private readonly CommissionService $commissions) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('commissions.view_own') || $user->can('commissions.view_team'), 403);

        $query = CommissionEntry::query()->with('order:id,uuid,number')->latest('id');
        if (! $user->can('commissions.view_team')) {
            $query->where('earner_id', $user->id); // view_own فقط
        }
        if ($state = $request->query('state')) {
            $query->where('state', $state);
        }
        if ($earner = $request->query('earner_id')) {
            $query->where('earner_id', $earner);
        }
        if ($type = $request->query('earner_type')) {
            $query->where('earner_type', $type);
        }

        $entries = $query->paginate(30);

        return response()->json([
            'data' => $entries->getCollection()->map(fn (CommissionEntry $e) => [
                'id' => $e->id,
                'earner_type' => $e->earner_type,
                'earner_id' => $e->earner_id,
                'order' => $e->order?->number,
                'entry_type' => $e->entry_type,
                'basis' => $e->basis,
                'rate' => $e->rate,
                'amount' => $e->amount,
                'state' => $e->state,
            ])->all(),
            'meta' => ['total' => $entries->total(), 'current_page' => $entries->currentPage()],
        ]);
    }

    public function statement(Request $request, int $earnerId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('commissions.view_team') || $user->id === $earnerId, 403);
        $type = $request->query('earner_type', 'sales');

        return response()->json(['data' => $this->commissions->statement($earnerId, $type)]);
    }

    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate(['entry_ids' => ['required', 'array', 'min:1'], 'entry_ids.*' => ['integer']]);
        $count = $this->commissions->approve($data['entry_ids'], $request->user());

        return response()->json(['data' => ['approved' => $count]]);
    }

    public function payout(PayoutRequest $request): JsonResponse
    {
        $data = $request->validated();
        $payout = $this->commissions->payout(
            $request->user(), (int) $data['earner_id'], $data['earner_type'], $data['entry_ids'], $data['reference'] ?? null,
        );

        return response()->json(['data' => [
            'id' => $payout->uuid, 'total' => $payout->total, 'reference' => $payout->reference, 'count' => $payout->entries->count(),
        ]]);
    }
}
