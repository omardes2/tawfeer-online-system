<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\PayrollLine;
use App\Modules\Hr\Models\PayrollRun;
use App\Support\NumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * مسيّر الرواتب — من العقد إلى القيد إلى الصرف.
 *
 * ## ثلاث خطوات لا خطوة
 *
 * **توليد** (مسودّة) → **ترحيل** (قيد) → **صرف** (سندات).
 *
 * الفصل بينها ليس تعقيدًا: المسودّة تُراجَع وتُصحَّح وتُحذف بلا أثر، والترحيل
 * يُثبت المصروف في شهره سواءٌ دُفع أم لا، والصرف يُطفئ الالتزام حين تُدفع
 * النقدية. ودمجُها في زرٍّ واحد يعني أن كل خطأ إدخالٍ يصير قيدًا يحتاج عكسًا.
 *
 * ## القيدان
 *
 * ```
 * الترحيل:   مدين  ٥٢٠٠ مصروف الرواتب والأجور   = صافي المسيّر
 *            دائن  ٢٢٠٠ رواتب مستحقة            = صافي المسيّر
 *
 * ونهاية الخدمة قيدٌ ثانٍ مستقلّ:
 *            مدين  ٥٢١٠ مصروف مكافأة نهاية الخدمة
 *            دائن  ٢٢١٠ مخصّص مكافأة نهاية الخدمة
 *
 * الصرف:     مدين  ٢٢٠٠ رواتب مستحقة            (سند صرف لكل موظف)
 *            دائن  الخزينة
 * ```
 *
 * **المصروف هو الصافي لا الإجماليّ.** الخصم على الراتب — إجازةٌ بلا أجر أو
 * جزاء — ليس التزامًا على الشركة لأحد: هو مبلغٌ لم تستحقّه الذمّة أصلًا.
 * فتحميلُه مصروفًا ثم تقييدُه دائنًا لجهةٍ ما يخترع دَينًا لا وجود له.
 *
 * **وسندٌ لكل موظف لا سندٌ للمسيّر.** الرواتب تُصرف متفرّقة — واحدٌ نقدًا
 * واليوم، وآخر بنكًا الأسبوع القادم. وسندٌ جامع يمنع ذلك ويُخفي مَن قبض
 * ومَن لم يقبض.
 *
 * ## ولماذا لا تُدمج العمولات هنا
 *
 * للعمولات دفترُها الخاصّ بحالاتٍ ومسارِ اعتمادٍ وسنداتِ صرفٍ من نوع
 * `payment`. وسحبُها إلى المسيّر يجعل خللًا في الرواتب يمسّ دفترَ العمولات،
 * ويفتح باب دفعِها مرّتين. فهي تُعرَض في ملفّ الموظف للاطّلاع، وتُدفع من
 * شاشتها.
 */
class PayrollService
{
    public const SALARY_EXPENSE_ACCOUNT = '5200';

    public const SALARY_PAYABLE_ACCOUNT = '2200';

    /**
     * قاسم اليوم الواحد. ثلاثون يومًا لا عددُ أيام الشهر: وإلا اختلفت قيمة
     * يوم الإجازة بين شباط وآذار للراتب نفسه.
     */
    private const DAYS_PER_MONTH = 30;

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly VoucherService $vouchers,
        private readonly LeaveService $leaves,
        private readonly EndOfServiceService $endOfService,
    ) {}

    // ————————————————————————— التوليد —————————————————————————

    /**
     * توليد مسيّر الشهر مسودّةً من العقود السارية.
     *
     * يُعاد توليدُه ما دام مسودّة: البنود تُمحى وتُبنى من جديد، فتصحيحُ راتبٍ
     * أو إضافةُ موظفٍ لا يحتاج حذف المسيّر.
     */
    public function generate(int $year, int $month, User $actor): PayrollRun
    {
        $this->assertPeriod($year, $month);

        return DB::transaction(function () use ($year, $month, $actor) {
            /*
            | **البحث يشمل المحذوف ناعمًا.**
            |
            | الفهرس `payroll_runs_period_unique` على (السنة، الشهر) لا يعرف الحذف
            | الناعم: مسيّرٌ حُذف يبقى محتلًّا شهره في قاعدة البيانات. وكان البحث
            | يُخفي المحذوف فلا يجده، فيمضي إلى الإدراج ويصطدم بالفهرس —
            | UniqueConstraintViolation، أي خطأ ٥٠٠ في الشاشة بلا رسالة تدلّ عليه.
            |
            | والمحذوف يُستعاد لا يُتجاوز: الحذف لا يُسمح به إلّا للمسودّة
            | (`PayrollController::destroy`)، فاستعادتُها لإعادة التوليد آمنة —
            | وبنودُها تُمحى وتُبنى من جديد أدناه. ويعود المسيّر برقمه الأول فلا
            | تُحرق أرقامٌ بلا مستند.
            */
            $run = PayrollRun::withTrashed()
                ->where('period_year', $year)->where('period_month', $month)->first();

            if ($run && ! $run->isDraft()) {
                throw ValidationException::withMessages([
                    'period' => __('مسيّر :p مُرحَّل — يُصحَّح بالعكس لا بإعادة التوليد.', ['p' => $run->periodLabel()]),
                ]);
            }

            if ($run && $run->trashed()) {
                $run->restore();
            }

            $run ??= PayrollRun::create([
                'number' => NumberGenerator::next('payroll_runs', 'number', 'PR', $year),
                'period_year' => $year,
                'period_month' => $month,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            $run->lines()->delete();

            $periodEnd = $run->periodEnd()->toDateString();

            $profiles = EmployeeProfile::with('user')
                ->where('status', 'active')
                // من عُيّن بعد نهاية الشهر لم يعمل فيه.
                ->whereDate('hire_date', '<=', $periodEnd)
                ->get();

            foreach ($profiles as $profile) {
                $salary = $profile->salaryOn($periodEnd);

                // بلا عقدٍ ساري لا بند: موظفٌ لم يُسجَّل راتبه بعد يُترك ظاهرًا
                // في الشاشة بتنبيه، ولا يُخترع له صفر.
                if (! $salary) {
                    continue;
                }

                $this->buildLine($run, $profile, $salary, $year, $month);
            }

            return $this->refreshTotals($run);
        });
    }

    private function buildLine(PayrollRun $run, EmployeeProfile $profile, $salary, int $year, int $month): PayrollLine
    {
        $basic = round((float) $salary->basic_salary, 2);
        $allowances = round((float) $salary->allowances, 2);

        $unpaidDays = $this->leaves->unpaidDaysInMonth($profile, $year, $month);
        $unpaidAmount = round(($basic + $allowances) / self::DAYS_PER_MONTH * $unpaidDays, 2);

        // الخصم لا يتجاوز الاستحقاق: راتبٌ بالسالب ليس دَينًا على الموظف.
        $unpaidAmount = min($unpaidAmount, $basic + $allowances);

        return PayrollLine::create([
            'payroll_run_id' => $run->id,
            'employee_profile_id' => $profile->id,
            'basic_salary' => $basic,
            'allowances' => $allowances,
            'other_additions' => 0,
            'unpaid_leave_days' => $unpaidDays,
            'unpaid_leave_amount' => $unpaidAmount,
            'other_deductions' => 0,
            'net' => round($basic + $allowances - $unpaidAmount, 2),
            'eos_provision' => $this->endOfService->monthlyAccrual($profile, $basic),
        ]);
    }

    /**
     * تعديل بندٍ في المسودّة — إضافةٌ أو خصمٌ يدويّ.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateLine(PayrollLine $line, array $data): PayrollLine
    {
        if (! $line->run->isDraft()) {
            throw ValidationException::withMessages(['status' => __('البنود تُعدَّل في المسودّة وحدها.')]);
        }

        $additions = round((float) ($data['other_additions'] ?? $line->other_additions), 2);
        $deductions = round((float) ($data['other_deductions'] ?? $line->other_deductions), 2);

        $earnings = round((float) $line->basic_salary + (float) $line->allowances + $additions, 2);
        $totalDeductions = round((float) $line->unpaid_leave_amount + $deductions, 2);

        if ($totalDeductions > $earnings) {
            throw ValidationException::withMessages([
                'other_deductions' => __('مجموع الخصم يتجاوز الاستحقاق — الراتب لا يكون بالسالب.'),
            ]);
        }

        $line->update([
            'other_additions' => $additions,
            'other_deductions' => $deductions,
            'note' => $data['note'] ?? $line->note,
            'net' => round($earnings - $totalDeductions, 2),
        ]);

        $this->refreshTotals($line->run->fresh());

        return $line->fresh();
    }

    // ————————————————————————— الترحيل —————————————————————————

    /**
     * ترحيل المسيّر: قيد الرواتب وقيد مخصّص نهاية الخدمة.
     *
     * idempotent: المُرحَّل يعود كما هو بلا قيدٍ ثانٍ.
     */
    public function post(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->isPosted()) {
            return $run;
        }

        if ($run->status === 'reversed') {
            throw ValidationException::withMessages(['status' => __('المسيّر المعكوس لا يُرحَّل ثانيةً.')]);
        }

        $run = $this->refreshTotals($run);

        if ($run->lines()->count() === 0) {
            throw ValidationException::withMessages(['lines' => __('لا بنود في المسيّر.')]);
        }

        if ((float) $run->total_net <= 0) {
            throw ValidationException::withMessages(['total' => __('صافي المسيّر صفر — لا شيء يُرحَّل.')]);
        }

        $date = $run->periodEnd()->toDateString();

        return DB::transaction(function () use ($run, $actor, $date) {
            $entry = $this->accounting->postEntry([
                'entry_date' => $date,
                'description' => __('رواتب :p', ['p' => $run->periodLabel()]),
                'source' => 'payroll',
                'reference_type' => 'payroll_run',
                'reference_id' => $run->id,
            ], [
                ['account_code' => self::SALARY_EXPENSE_ACCOUNT, 'debit' => (float) $run->total_net, 'credit' => 0],
                ['account_code' => self::SALARY_PAYABLE_ACCOUNT, 'debit' => 0, 'credit' => (float) $run->total_net],
            ]);

            $run->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);

            $this->postEndOfService($run, $actor, $date);

            return $run->fresh('lines');
        });
    }

    /** قيدُ التراكم الشهريّ + حركاتُه في دفتر المخصّص. */
    private function postEndOfService(PayrollRun $run, User $actor, string $date): void
    {
        $total = round((float) $run->total_eos, 2);

        if ($total <= 0) {
            return;
        }

        $entry = $this->accounting->postEntry([
            'entry_date' => $date,
            'description' => __('مخصّص نهاية الخدمة :p', ['p' => $run->periodLabel()]),
            'source' => 'payroll',
            'reference_type' => 'payroll_run_eos',
            'reference_id' => $run->id,
        ], [
            ['account_code' => EndOfServiceService::EXPENSE_ACCOUNT, 'debit' => $total, 'credit' => 0],
            ['account_code' => EndOfServiceService::PROVISION_ACCOUNT, 'debit' => 0, 'credit' => $total],
        ]);

        $run->update(['eos_journal_entry_id' => $entry->id]);

        foreach ($run->lines()->with('profile')->get() as $line) {
            $this->endOfService->recordAccrual($line->profile, $run, (float) $line->eos_provision, $actor);
        }
    }

    // ————————————————————————— الصرف —————————————————————————

    /**
     * صرف رواتب بنودٍ بعينها — سند صرفٍ لكل موظف.
     *
     * @param  array<int, int>  $lineIds
     * @return int عدد البنود التي صُرفت
     */
    public function pay(PayrollRun $run, array $lineIds, int $treasuryId, User $actor): int
    {
        if (! $run->isPosted()) {
            throw ValidationException::withMessages(['status' => __('يُصرف المسيّر المُرحَّل وحده.')]);
        }

        $payable = Account::where('code', self::SALARY_PAYABLE_ACCOUNT)->firstOrFail();

        $lines = $run->lines()
            ->whereIn('id', $lineIds)
            // المدفوع لا يُدفع ثانيةً — والفلتر هنا لا في الشاشة: طلبٌ مُعاد
            // بالضغط مرّتين يصل الخادمَ مرّتين.
            ->whereNull('financial_voucher_id')
            ->where('net', '>', 0)
            ->with('profile.user')
            ->get();

        if ($lines->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($lines, $run, $treasuryId, $actor, $payable) {
            foreach ($lines as $line) {
                $voucher = $this->vouchers->create('payment', [
                    'voucher_date' => now()->toDateString(),
                    'treasury_id' => $treasuryId,
                    'counter_account_id' => $payable->id,
                    'employee_id' => $line->profile->user_id,
                    'party_name' => $line->profile->user?->name,
                    'amount' => (float) $line->net,
                    'category' => 'payroll',
                    'reference' => $run->number,
                    'description' => __('راتب :p — :n', [
                        'p' => $run->periodLabel(),
                        'n' => $line->profile->user?->name,
                    ]),
                ]);

                $this->vouchers->approve($voucher, $actor);
                $this->vouchers->post($voucher, $actor);

                $line->update(['financial_voucher_id' => $voucher->id]);
            }

            // «مدفوع» حين لا يبقى بندٌ بلا سند — لا حين تُصرف أوّل دفعة.
            if ($run->lines()->whereNull('financial_voucher_id')->where('net', '>', 0)->doesntExist()) {
                $run->update(['status' => 'paid']);
            }

            return $lines->count();
        });
    }

    // ————————————————————————— العكس —————————————————————————

    /**
     * عكس مسيّر مُرحَّل — قيدٌ عاكس لا حذف.
     *
     * ويُمنع بعد صرف أيّ بند: النقدية خرجت فعلًا، وعكسُ الالتزام وحده يترك
     * سندَ صرفٍ يُطفئ دَينًا لم يعد قائمًا. تُعكس السنداتُ أوّلًا من شاشتها.
     */
    public function reverse(PayrollRun $run, User $actor, ?string $reason = null): PayrollRun
    {
        if (! $run->isPosted()) {
            throw ValidationException::withMessages(['status' => __('يُعكس المسيّر المُرحَّل وحده.')]);
        }

        if ($run->lines()->whereNotNull('financial_voucher_id')->exists()) {
            throw ValidationException::withMessages([
                'status' => __('صُرفت بنودٌ من هذا المسيّر — اعكس سنداتها أوّلًا.'),
            ]);
        }

        return DB::transaction(function () use ($run, $reason) {
            foreach ([$run->journalEntry, $run->eosJournalEntry] as $entry) {
                if ($entry && ! $entry->isReversed()) {
                    $this->accounting->reverse($entry, [
                        'description' => $reason ?: __('عكس مسيّر :p', ['p' => $run->periodLabel()]),
                    ]);
                }
            }

            // حركاتُ المخصّص تُحذف لا تُعكس: لم تكن دفعًا بل تراكمًا، وقيدُها
            // عُكس. وتركُها يُبقي رصيدًا لالتزامٍ أُلغي قيدُه.
            $run->lines()->with('profile')->get()->each(
                fn ($line) => $line->profile?->endOfServiceEntries()
                    ->where('payroll_run_id', $run->id)->delete(),
            );

            $run->update(['status' => 'reversed', 'notes' => $reason ?: $run->notes]);

            return $run->fresh();
        });
    }

    // ————————————————————————— مساعدات —————————————————————————

    /** إعادة جمع الإجماليّات من البنود — لا رقمَ محفوظًا يفترق عنها. */
    public function refreshTotals(PayrollRun $run): PayrollRun
    {
        $lines = $run->lines()->get();

        $run->update([
            'total_earnings' => round($lines->sum(fn (PayrollLine $l) => $l->earnings()), 2),
            'total_deductions' => round($lines->sum(fn (PayrollLine $l) => $l->deductions()), 2),
            'total_net' => round((float) $lines->sum('net'), 2),
            'total_eos' => round((float) $lines->sum('eos_provision'), 2),
        ]);

        return $run->fresh();
    }

    private function assertPeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages(['period_month' => __('الشهر بين ١ و١٢.')]);
        }

        // شهرٌ لم يبدأ بعدُ لا رواتب فيه.
        if (Carbon::create($year, $month, 1)->startOfMonth()->isFuture()) {
            throw ValidationException::withMessages(['period' => __('لا يُولَّد مسيّر لشهرٍ لم يبدأ.')]);
        }
    }
}
