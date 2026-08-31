<?php

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\EmployeeProfileRequest;
use App\Http\Requests\Hr\StoreLeaveRequest;
use App\Http\Requests\Hr\StoreSalaryRequest;
use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Hr\Models\EmployeeLeave;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Services\EmployeeFinanceService;
use App\Modules\Hr\Services\EndOfServiceService;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * الرواتب والموظفون — قائمة الموظفين وملفّ كلٍّ منهم.
 *
 * ## العمولات هنا للاطّلاع لا للصرف
 *
 * ملفّ الموظف يعرض رصيد عمولاته لأن من يقرأ استحقاقاته يريد صورةً واحدة.
 * لكنّ الدفع يبقى في شاشة العمولات: لها دفترُها بحالاتٍ ومسارِ اعتماد، وفتحُ
 * بابٍ ثانٍ للصرف يفتح باب الدفع مرّتين.
 */
class EmployeeController extends Controller
{
    public function __construct(
        private readonly LeaveService $leaves,
        private readonly EndOfServiceService $endOfService,
        private readonly CommissionService $commissions,
        private readonly EmployeeFinanceService $finance,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('hr.employees.view');

        $status = in_array($request->query('status'), ['active', 'ended'], true)
            ? $request->query('status')
            : 'active';

        $year = (int) ($request->integer('year') ?: today()->year);

        $profiles = EmployeeProfile::with(['user:id,name,email,job_title', 'branch:id,name'])
            ->where('status', $status)
            ->when($request->filled('q'), fn ($q) => $q->whereHas(
                'user',
                fn ($u) => $u->where('name', 'like', '%'.$request->string('q').'%'),
            ))
            ->get()
            ->sortBy(fn (EmployeeProfile $p) => $p->user?->name)
            ->values();

        $today = today()->toDateString();
        $eosBalances = $this->endOfService->balances($profiles->pluck('id')->all());
        $advances = $this->finance->advanceBalances($profiles->pluck('id')->all());

        $rows = $profiles->map(function (EmployeeProfile $profile) use ($year, $today, $eosBalances, $advances) {
            $salary = $profile->salaryOn($today);
            $leave = $this->leaves->balance($profile, $year);

            return [
                'profile' => $profile,
                'basic' => $salary ? (float) $salary->basic_salary : null,
                'allowances' => $salary ? (float) $salary->allowances : 0.0,
                'gross' => $salary?->gross(),
                'leave_remaining' => $leave['remaining'],
                'leave_taken' => $leave['taken'],
                'eos' => (float) $eosBalances->get($profile->id, 0.0),
                'advance' => (float) $advances->get($profile->id, 0.0),
            ];
        });

        return view('admin.hr.employees.index', [
            'rows' => $rows,
            'status' => $status,
            'year' => $year,
            'q' => (string) $request->query('q', ''),
            'totals' => [
                'headcount' => $rows->count(),
                'monthly' => round((float) $rows->sum(fn ($r) => $r['gross'] ?? 0), 2),
                'eos' => round((float) $rows->sum('eos'), 2),
                // السلف أصلٌ للشركة لا مصروف — مالٌ عند الموظفين يعود.
                'advances' => round((float) $rows->sum('advance'), 2),
                // بلا عقدٍ مسجَّل لا يدخل الموظف الكشف — يُعدّ هنا كي يُرى.
                'without_salary' => $rows->whereNull('basic')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('hr.employees.manage');

        return view('admin.hr.employees.create', $this->formData());
    }

    public function store(EmployeeProfileRequest $request): RedirectResponse
    {
        $profile = EmployeeProfile::create($request->validated() + ['created_by' => $request->user()->id]);

        return redirect()->route('admin.hr.employees.show', $profile)
            ->with('success', __('أُنشئ ملفّ الموظف. سجّل راتبه ليدخل كشف الرواتب.'));
    }

    public function show(EmployeeProfile $employee, Request $request): View
    {
        $this->authorize('hr.employees.view');

        $year = (int) ($request->integer('year') ?: today()->year);
        $employee->load(['user', 'branch', 'salaries.creator', 'leaves.creator']);

        $earnerType = $employee->user?->hasRole('affiliate') ? 'affiliate' : 'sales';

        return view('admin.hr.employees.show', [
            'employee' => $employee,
            'year' => $year,
            'currentSalary' => $employee->salaryOn(today()->toDateString()),
            'leave' => $this->leaves->balance($employee, $year),
            'leaves' => $employee->leaves->where('from_date', '>=', $year.'-01-01')
                ->where('from_date', '<=', $year.'-12-31'),
            'eosBalance' => $this->endOfService->balance($employee),
            'eosEntries' => $employee->endOfServiceEntries()->with('voucher:id,number,status')
                ->orderByDesc('entry_date')->orderByDesc('id')->limit(50)->get(),
            'serviceMonths' => $employee->serviceMonthsUntil(today()->toDateString()),
            // المكافآت والسلف: خارج العقد فلا تدخل كشف الرواتب ولا يتراكم
            // عليها مخصّص نهاية الخدمة.
            'advanceBalance' => $this->finance->advanceBalance($employee),
            'bonusTotal' => $this->finance->bonusTotal($employee, $year),
            'financeEntries' => $this->finance->ledger($employee)->take(50),
            // للاطّلاع فقط — الصرف من شاشة العمولات.
            'commissions' => $employee->user
                ? $this->commissions->balance($employee->user_id, $earnerType)
                : null,
            'earnerType' => $earnerType,
            'payrollLines' => $employee->payrollLines()->with('run')
                ->get()->sortByDesc(fn ($l) => [$l->run?->period_year, $l->run?->period_month])->take(12),
            'treasuries' => Treasury::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(EmployeeProfile $employee): View
    {
        $this->authorize('hr.employees.manage');

        return view('admin.hr.employees.edit', $this->formData() + ['employee' => $employee]);
    }

    public function update(EmployeeProfileRequest $request, EmployeeProfile $employee): RedirectResponse
    {
        $employee->update($request->validated());

        return redirect()->route('admin.hr.employees.show', $employee)
            ->with('success', __('حُدّث ملفّ الموظف.'));
    }

    // ————————————————————————— الراتب —————————————————————————

    public function storeSalary(StoreSalaryRequest $request, EmployeeProfile $employee): RedirectResponse
    {
        // `updateOrCreate` لا `create`: الإدخال يُعاد حتى يستقرّ الرقم، فيجب
        // أن يُحدِّث صفّ تاريخ السريان لا أن يصطدم بالفهرس الفريد.
        $employee->salaries()->updateOrCreate(
            ['effective_from' => $request->date('effective_from')->toDateString()],
            [
                'basic_salary' => $request->float('basic_salary'),
                'allowances' => $request->float('allowances') ?: 0,
                'note' => $request->input('note'),
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', __('سُجّل الراتب. الكشوفات المُرحَّلة لا تتأثّر.'));
    }

    // ————————————————————————— الإجازات —————————————————————————

    public function storeLeave(StoreLeaveRequest $request, EmployeeProfile $employee): RedirectResponse
    {
        $employee->leaves()->create($request->validated() + ['created_by' => $request->user()->id]);

        $balance = $this->leaves->balance($employee, (int) $request->date('from_date')->year);

        // الرصيد السالب لا يُمنَع بل يُنبَّه عليه: الشركة قد تمنح إجازةً على
        // حساب السنة القادمة، ومنعُ التسجيل يدفع إلى ألّا يُسجَّل شيء.
        return back()->with(
            $balance['remaining'] < 0 ? 'error' : 'success',
            $balance['remaining'] < 0
                ? __('سُجّلت الإجازة — والرصيد صار بالسالب (:d يومًا).', ['d' => $balance['remaining']])
                : __('سُجّلت الإجازة. الرصيد المتبقّي :d يومًا.', ['d' => $balance['remaining']]),
        );
    }

    public function destroyLeave(EmployeeProfile $employee, EmployeeLeave $leave): RedirectResponse
    {
        $this->authorize('hr.employees.manage');

        abort_unless($leave->employee_profile_id === $employee->id, 404);

        $leave->delete();

        return back()->with('success', __('حُذفت الإجازة.'));
    }

    // ————————————————————————— نهاية الخدمة —————————————————————————

    public function settleEndOfService(Request $request, EmployeeProfile $employee): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->endOfService->settle(
                $employee,
                (float) $data['amount'],
                (int) $data['treasury_id'],
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('صُرفت مكافأة نهاية الخدمة وأُطفئ المخصّص بقيمتها.'));
    }

    public function adjustEndOfService(Request $request, EmployeeProfile $employee): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        $data = $request->validate([
            'amount' => ['required', 'numeric'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->endOfService->adjust($employee, (float) $data['amount'], $request->user(), $data['note']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('سُجّلت التسوية في دفتر المخصّص. قيّدها محاسبيًّا بسندٍ مستقلّ إن لزم.'));
    }

    // ————————————————————————— المكافآت والسلف —————————————————————————

    /**
     * منح مكافأةٍ أو سلفة، أو تسديد سلفة — كلٌّ بسندٍ يخرج من الخزينة.
     *
     * ثلاثتُها فعلٌ واحد بثلاثة حسابات، فمسارٌ واحد يحملها: تشعّبها إلى ثلاثة
     * مساراتٍ يُكرّر التحقّق والصلاحية ثلاث مرّات ويُغري باختلافها.
     */
    public function storeFinanceEntry(Request $request, EmployeeProfile $employee): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        $data = $request->validate([
            'kind' => ['required', 'in:bonus,advance,advance_repayment'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            match ($data['kind']) {
                'bonus' => $this->finance->grantBonus(
                    $employee, (float) $data['amount'], (int) $data['treasury_id'], $request->user(), $data['note'] ?? null,
                ),
                'advance' => $this->finance->grantAdvance(
                    $employee, (float) $data['amount'], (int) $data['treasury_id'], $request->user(), $data['note'] ?? null,
                ),
                'advance_repayment' => $this->finance->repayAdvance(
                    $employee, (float) $data['amount'], (int) $data['treasury_id'], $request->user(), $data['note'] ?? null,
                ),
            };
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        }

        return back()->with('success', match ($data['kind']) {
            'bonus' => __('سُجّلت المكافأة — مصروفُ شهرها، ولا تدخل الراتب الثابت.'),
            'advance' => __('سُجّلت السلفة — دَينٌ على الموظف في «سلف الموظفين»، لا مصروف.'),
            'advance_repayment' => __('سُجّل التسديد وأُطفئ من السلفة القائمة بقيمته.'),
        });
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            // من له ملفٌّ لا يظهر في القائمة: ملفّان لشخصٍ يجعلان راتبه بندين.
            'users' => User::whereNotIn('id', EmployeeProfile::pluck('user_id'))
                ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'job_title']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ];
    }
}
