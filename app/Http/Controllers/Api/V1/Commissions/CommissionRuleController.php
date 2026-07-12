<?php

namespace App\Http\Controllers\Api\V1\Commissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commissions\CommissionRuleRequest;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Services\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * إدارة قواعد العمولة/الأرباح (Phase 4.2). الأولويّة تُحسَب من النطاق (حتمية).
 */
class CommissionRuleController extends Controller
{
    public function __construct(private readonly CommissionService $commissions) {}

    public function index(): JsonResponse
    {
        $rules = CommissionRule::orderByDesc('priority')->orderByDesc('id')->paginate(30);

        return response()->json(['data' => $rules->items(), 'meta' => ['total' => $rules->total()]]);
    }

    public function store(CommissionRuleRequest $request): JsonResource
    {
        $rule = new CommissionRule($request->validated());
        $rule->created_by = $request->user()->id;
        $rule->priority = $this->commissions->ruleScorePriority($rule);
        $rule->save();

        return new JsonResource($rule);
    }

    public function update(CommissionRuleRequest $request, CommissionRule $rule): JsonResource
    {
        $rule->fill($request->validated());
        $rule->priority = $this->commissions->ruleScorePriority($rule);
        $rule->save();

        return new JsonResource($rule->refresh());
    }

    public function destroy(CommissionRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
