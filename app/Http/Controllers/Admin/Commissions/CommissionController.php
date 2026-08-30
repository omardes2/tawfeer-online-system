<?php

namespace App\Http\Controllers\Admin\Commissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commissions\CommissionRuleRequest;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPayout;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Support\XlsxExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * لوحة العمولات/الأرباح (Phase 4.2) — واجهات إدارة RTL: الدفتر (فلترة بالحالة)،
 * الاعتماد/الصرف بالدفعات، القواعد، وكشف الحساب. المنطق في CommissionService.
 */
class CommissionController extends Controller
{
    /** قيمة الفلتر التي تعني «بلا تصفية». */
    private const ALL_STATES = 'all';

    /**
     * حالات كشف الحساب — و**«مستحقّة» هي الافتراض**.
     *
     * الكشف يُقرأ ليُصرَف عليه، و«قيد الانتظار» حركةٌ لم يصل مالُها من شركة
     * التوصيل بعد: ظهورُها بين المستحقّات يُوهم المراجع بأنها واجبة الدفع.
     * فتُخفى افتراضًا وتبقى في متناول من يطلبها من الفلتر — لا تُحذف من الشاشة
     * كي لا يبحث عنها صاحبُها فلا يجدها.
     */
    private const STATEMENT_STATES = [
        'eligible', 'approved', 'paid', 'pending', 'cancelled', 'reversed', self::ALL_STATES,
    ];

    public function __construct(private readonly CommissionService $commissions) {}

    /** حالة الكشف المطلوبة — تُحصر في المعروف حتى لا يُفلتَر بقيمةٍ من العنوان. */
    private function statementState(Request $request): string
    {
        $state = (string) $request->query('state', 'eligible');

        return in_array($state, self::STATEMENT_STATES, true) ? $state : 'eligible';
    }

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
                // بتاريخ الطلب كما في الكشف تمامًا — وإلّا اختلف رقم القائمة عن
                // رقم كشف الشخص نفسه للفترة نفسها، وهو أسوأ من خطأٍ ظاهر.
                $period = (float) $this->inPeriod($u->id, $type, $range)
                    ->whereIn('state', ['eligible', 'approved', 'paid'])->sum('amount');

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

    /**
     * تصدير كشف الفترة — **كل حركاتها لا صفحةً منها**.
     *
     * الشاشة مقسّمة إلى صفحاتٍ من ثلاثين، والتصدير للمحاسبة والمراجعة: صفحةٌ
     * واحدة تُنتج كشفًا ناقصًا يُبنى عليه صرفٌ خاطئ. فيُبثّ الصفّ تلو الآخر
     * (`chunk`) بلا تحميل الكل في الذاكرة.
     *
     * ويتبع الفلتر الظاهر على الشاشة: ملفٌّ يخالف ما أمام عينَي من صدّره
     * أسوأ من غياب التصدير.
     */
    private function statementXlsx(int $earnerId, string $type, array $range, string $from, string $to, string $state): BinaryFileResponse
    {
        $earner = User::find($earnerId);

        $head = [
            __('commissions.order'), __('رقم التتبّع'), __('الصنف'), __('commissions.order_date'),
            __('commissions.entry_type'), __('commissions.sale_price'), __('commissions.buy_price'),
            __('commissions.profit'), __('commissions.state'),
        ];

        $query = $this->statementEntries($earnerId, $type, $range, $state)->orderBy('id');

        $rows = function () use ($query) {
            $total = 0.0;

            foreach ($query->lazy(500) as $e) {
                $total += (float) $e->amount;

                yield [
                    $e->order?->number ?? '—',
                    $e->order?->tracking_number ?? '',
                    $e->variant?->product?->name ?? $e->variant?->sku ?? '—',
                    $e->order?->created_at?->format('Y-m-d') ?? '',
                    __('commissions.'.$e->entry_type),
                    $e->saleValue(),
                    $e->costValue(),
                    round((float) $e->amount, 2),
                    __('commissions.'.$e->state),
                ];
            }

            yield [];
            yield [__('الإجمالي'), '', '', '', '', '', '', round($total, 2), ''];
        };

        return XlsxExporter::download(
            'commission-statement-'.($earner?->id ?? $earnerId).'-'.$from.'_'.$to,
            $head,
            $rows,
            [
                // ترويسةٌ تعرّف الكشف: ملفٌّ بلا اسم صاحبه ولا فترته لا يصلح مستندًا.
                [__('كشف حساب'), $earner?->name ?? '—'],
                [__('من'), $from, __('إلى'), $to],
                [__('commissions.state'), $state === self::ALL_STATES ? __('commissions.all_states') : __('commissions.'.$state)],
            ],
        );
    }

    /**
     * حركات الكشف بعد الفلترة بالحالة.
     *
     * الشاشة والتصدير يقرآن من هنا معًا — وإلّا انحرف أحدهما عن الآخر عند أول
     * تعديل على الفلتر.
     */
    private function statementEntries(int $earnerId, string $type, array $range, string $state)
    {
        return $this->inPeriod($earnerId, $type, $range)
            ->when($state !== self::ALL_STATES, fn ($q) => $q->where('state', $state))
            ->with([
                'order:id,uuid,number,created_at,tracking_number',
                'orderItem:id,qty,unit_price',
                'variant.product:id,name', 'variant.attributeValues',
            ]);
    }

    /**
     * حركات مستفيدٍ ضمن فترة — **بتاريخ الطلب** لا بتاريخ تسجيل الحركة.
     *
     * الكشف يعرض عمود «تاريخ الطلب»، وكان الفلتر يُصفّي على `created_at` الحركة.
     * وهما يتطابقان في الحالة العادية (تُنشأ الحركة عند تأكيد الطلب) **ويفترقان
     * عند التصحيح وإعادة الاحتساب**: حركةٌ لطلبِ تمّوز تُسجَّل في آب فتظهر في
     * فلتر آب وتغيب عن تمّوز — بينما السطر نفسه يقول «تاريخ الطلب: تمّوز».
     * فالمستخدم يرى تناقضًا ولا يجد له سببًا.
     *
     * و`order_id` غير قابل للفراغ في المخطّط، فلا حركة بلا طلب ولا حالة تسقط
     * من كل فترة.
     */
    private function inPeriod(int $earnerId, string $type, array $range)
    {
        return CommissionEntry::where('earner_id', $earnerId)
            ->where('earner_type', $type)
            ->whereHas('order', fn ($o) => $o->whereBetween('orders.created_at', $range));
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
            'rules' => CommissionRule::with(['user:id,name', 'product:id,name', 'category:id,name', 'branch:id,name'])
                ->orderByDesc('priority')->latest('id')->paginate(25),
            // قوائم بالأسماء بدل إدخال المعرّفات يدويًا.
            'people' => User::whereHas('roles', fn ($q) => $q->whereIn('name', ['sales', 'sales_supervisor', 'affiliate']))
                ->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeRule(CommissionRuleRequest $request): RedirectResponse
    {
        $rule = new CommissionRule($request->safe()->except('rate_percent'));
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

    public function statement(Request $request, int $earnerId): View|BinaryFileResponse
    {
        $type = $request->query('earner_type', 'sales');
        [$fromStr, $toStr, $range] = $this->period($request);
        $state = $this->statementState($request);

        // مستحقّات الفترة (الأرباح المؤهّلة صافيةً ضمن المدى الزمني).
        // **لا تتبع فلتر الحالة**: بطاقات الأرصدة تقول ما للمستفيد كاملًا،
        // وربطُها بالفلتر يجعل الرصيد يتغيّر بتغيّر العرض وهو لا يتغيّر.
        $periodEarned = (float) $this->inPeriod($earnerId, $type, $range)
            ->whereIn('state', ['eligible', 'approved', 'paid'])->sum('amount');

        if (in_array($request->query('export'), ['xlsx', 'csv'], true)) {
            return $this->statementXlsx($earnerId, $type, $range, $fromStr, $toStr, $state);
        }

        return view('admin.commissions.statement', [
            'earnerId' => $earnerId,
            'earnerType' => $type,
            'earner' => User::find($earnerId),
            'statement' => $this->commissions->statement($earnerId, $type),
            'balance' => $this->commissions->balance($earnerId, $type),
            'periodEarned' => round($periodEarned, 2),
            'from' => $fromStr,
            'to' => $toStr,
            'state' => $state,
            'states' => self::STATEMENT_STATES,
            // الصنف مع الطلب: الحركة **لكل بند** لا لكل طلب، فالطلب ذو الصنفين
            // يعطي سطرين برقمٍ واحد — يبدوان تكرارًا لمن يقرأ الكشف، وهما بندان
            // مختلفان. وبند الطلب يُحمَّل لأن سعر البيع يُقرأ منه لا من `basis`.
            //
            // ورقم التتبّع يُقرأ من الطلب عرضًا فقط — Protected Delivery
            // Integration — Do Not Modify: لا يُكتب ولا يُطلب من الشركة.
            'entries' => $this->statementEntries($earnerId, $type, $range, $state)
                ->latest('id')->paginate(30)->withQueryString(),
            // `uuid` إلزاميّ في التحديد: مفتاح مسار السند هو الـuuid لا الـid
            // (HasUuid)، وبدونه يُبنى رابط «عرض السند» بمفتاحٍ فارغ فلا يفتح.
            // و`notes` تُعرض في الجدول فلا تُجلب بطلبٍ ثانٍ لكل صفّ.
            'payouts' => CommissionPayout::where('earner_id', $earnerId)->where('earner_type', $type)
                ->with(['voucher:id,uuid,number,status,kind,notes', 'treasury:id,name'])->latest('id')->get(),
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
