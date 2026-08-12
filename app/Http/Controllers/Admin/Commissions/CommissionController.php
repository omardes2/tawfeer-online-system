<?php

namespace App\Http\Controllers\Admin\Commissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commissions\CommissionRuleRequest;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPayout;
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

    /**
     * الصفحة الرئيسية: قائمة الأشخاص (موظفو المبيعات ثم المسوّقون) بأرصدتهم — مستحق
     * الفترة (حسب الفلتر) والإجمالي والمدفوع والرصيد. الضغط على الاسم يفتح ملفه
     * (كل حركاته + الصرف). دفتر الحركات التفصيلي انتقل إلى ledger.
     */
    public function index(Request $request): View
    {
        [$from, $to, $range] = $this->period($request);

        // whereHas بدل User::role() — الأخير يرمي RoleDoesNotExist إن غاب دور من القاعدة
        // (كما على إنتاج لم يُزرع فيه sales_supervisor) فتسقط الصفحة بخطأ 500.
        $people = fn (array $roles, string $type) => User::whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->orderBy('name')->get()
            ->map(function (User $u) use ($type, $range) {
                $period = (float) CommissionEntry::where('earner_id', $u->id)->where('earner_type', $type)
                    ->whereIn('state', ['eligible', 'approved', 'paid'])
                    ->whereBetween('created_at', $range)->sum('amount');

                return ['user' => $u, 'period' => round($period, 2)] + $this->commissions->balance($u->id, $type);
            })->values();

        return view('admin.commissions.index', [
            'sales' => $people(['sales', 'sales_supervisor'], 'sales'),
            'affiliates' => $people(['affiliate'], 'affiliate'),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** دفتر الحركات التفصيلي (فلترة بالحالة + اعتماد بالدفعات) — كانت الصفحة الرئيسية سابقًا. */
    public function ledger(Request $request): View
    {
        $state = $request->query('state', 'eligible');
        $entries = CommissionEntry::with(['order:id,number', 'earner:id,name'])
            ->where('state', $state)->latest('id')->paginate(25)->withQueryString();

        return view('admin.commissions.ledger', ['entries' => $entries, 'state' => $state]);
    }

    /** @return array{0: string, 1: string, 2: array} from/to (نصًّا) والمدى الزمني للاستعلام */
    private function period(Request $request): array
    {
        $from = $request->date('from') ?: now()->startOfMonth();
        $to = $request->date('to') ?: now()->endOfMonth();

        return [$from->toDateString(), $to->toDateString(), [$from->copy()->startOfDay(), $to->copy()->endOfDay()]];
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
        [$fromStr, $toStr, $range] = $this->period($request);
        [$from, $to] = [$range[0], $range[1]];

        // مستحقّات الفترة (الأرباح المؤهّلة صافيةً ضمن المدى الزمني).
        $periodEarned = (float) CommissionEntry::where('earner_id', $earnerId)->where('earner_type', $type)
            ->whereIn('state', ['eligible', 'approved', 'paid'])
            ->whereBetween('created_at', $range)->sum('amount');

        return view('admin.commissions.statement', [
            'earnerId' => $earnerId,
            'earnerType' => $type,
            'earner' => User::find($earnerId),
            'statement' => $this->commissions->statement($earnerId, $type),
            'balance' => $this->commissions->balance($earnerId, $type),
            'periodEarned' => round($periodEarned, 2),
            'from' => $fromStr,
            'to' => $toStr,
            'entries' => CommissionEntry::where('earner_id', $earnerId)->where('earner_type', $type)
                ->whereBetween('created_at', $range)
                ->with('order:id,number')->latest('id')->paginate(30)->withQueryString(),
            'payouts' => CommissionPayout::where('earner_id', $earnerId)->where('earner_type', $type)
                ->with(['voucher:id,number,status,kind', 'treasury:id,name'])->latest('id')->get(),
            // البنوك أولًا (الصرف من الحسابات البنكية هو الأصل) ثم الخزائن النقدية.
            'treasuries' => Treasury::active()->orderByRaw("type = 'bank' desc")->orderBy('name')->get(),
        ]);
    }

    /** دفع أرباح مستفيد بمبلغ حرّ من خزينة/بنك — يُنشئ سند صرف مسودّة تعتمده المالية. */
    public function payProfit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'earner_id' => ['required', 'integer', 'exists:users,id'],
            'earner_type' => ['required', 'in:sales,affiliate'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            // اختياري: يُحسم تلقائيًا لحساب مصروف العمولات الافتراضي (تبسيط النموذج).
            'counter_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $counterId = $data['counter_account_id']
            ?? Account::where('code', config('accounting.commissions.expense_account', '5040'))->value('id');

        if (! $counterId) {
            return back()->withErrors(['counter_account_id' => __('حساب مصروف العمولات الافتراضي (5040) غير موجود في الدليل.')])->withInput();
        }

        $this->commissions->payAmount(
            $request->user(), (int) $data['earner_id'], $data['earner_type'], (float) $data['amount'],
            (int) $data['treasury_id'], (int) $counterId,
            $data['period_start'] ?? null, $data['period_end'] ?? null,
            $data['reference'] ?? null, $data['notes'] ?? null,
        );

        return back()->with('status', __('commissions.payment_recorded'));
    }
}
