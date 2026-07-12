<?php

namespace App\Http\Controllers\Admin\Commissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commissions\CommissionRuleRequest;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Services\CommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * لوحة العمولات/الأرباح (Phase 4.2) — واجهات إدارة RTL: الدفتر (فلترة بالحالة)،
 * الاعتماد/الصرف بالدفعات، القواعد، وكشف الحساب. المنطق في CommissionService.
 */
class CommissionController extends Controller
{
    public function __construct(private readonly CommissionService $commissions) {}

    public function index(Request $request): View
    {
        $state = $request->query('state', 'pending');
        $entries = CommissionEntry::with(['order:id,number', 'earner:id,name'])
            ->where('state', $state)->latest('id')->paginate(25)->withQueryString();

        return view('admin.commissions.index', ['entries' => $entries, 'state' => $state]);
    }

    public function rules(): View
    {
        return view('admin.commissions.rules', [
            'rules' => CommissionRule::latest('id')->paginate(25),
        ]);
    }

    public function storeRule(CommissionRuleRequest $request): RedirectResponse
    {
        $rule = new CommissionRule($request->validated());
        $rule->created_by = $request->user()->id;
        $rule->priority = $this->commissions->ruleScorePriority($rule);
        $rule->save();

        return back()->with('status', __('commissions.rule_saved'));
    }

    public function destroyRule(CommissionRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('status', __('commissions.rule_removed'));
    }

    public function approve(Request $request): RedirectResponse
    {
        $data = $request->validate(['entry_ids' => ['required', 'array'], 'entry_ids.*' => ['integer']]);
        $count = $this->commissions->approve($data['entry_ids'], $request->user());

        return back()->with('status', __('commissions.approved_count', ['count' => $count]));
    }

    public function payout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'earner_id' => ['required', 'integer'],
            'earner_type' => ['required', 'in:sales,affiliate'],
            'entry_ids' => ['required', 'array'],
            'entry_ids.*' => ['integer'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);
        $payout = $this->commissions->payout($request->user(), $data['earner_id'], $data['earner_type'], $data['entry_ids'], $data['reference'] ?? null);

        return back()->with('status', __('commissions.paid_total', ['total' => number_format((float) $payout->total, 2)]));
    }

    public function statement(Request $request, int $earnerId): View
    {
        $type = $request->query('earner_type', 'sales');

        return view('admin.commissions.statement', [
            'earnerId' => $earnerId,
            'earnerType' => $type,
            'statement' => $this->commissions->statement($earnerId, $type),
            'entries' => CommissionEntry::where('earner_id', $earnerId)->where('earner_type', $type)
                ->with('order:id,number')->latest('id')->paginate(30),
        ]);
    }
}
