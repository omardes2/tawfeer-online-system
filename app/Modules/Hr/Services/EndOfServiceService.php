<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\EndOfServiceEntry;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * مخصّص مكافأة نهاية الخدمة.
 *
 * ## القاعدة
 *
 * شهرٌ عن كل سنة خدمة. فالمتراكم في كل شهرٍ عملٍ هو **راتبٌ أساسيّ ÷ ١٢** —
 * البدلات خارجه لأنها مقابل تكاليفٍ لا أجر.
 *
 * ## ولماذا يتراكم شهريًّا لا يُحسب عند الخروج
 *
 * لأن الالتزام ينشأ بالعمل لا بالخروج. فشركةٌ تُوظّف عشرة منذ ثلاث سنوات
 * تدين لهم بثلاثين شهرَ راتب **الآن**، ولا يظهر ذلك في ميزانيّتها إن أُجّل
 * الحساب إلى يوم الاستقالة — فتُقرأ الشركة أغنى ممّا هي، وتفاجئها التصفية.
 *
 * والتراكم يُقيَّد مع كل مسيّر: مدين «مصروف مكافأة نهاية الخدمة» / دائن
 * «مخصّص مكافأة نهاية الخدمة». والتصفية تُطفئ المخصّص من الخزينة ولا تمرّ
 * بالمصروف ثانيةً — وإلّا حُمّلت السنةُ الأخيرة كلفةَ كل السنوات.
 *
 * ## والرصيد مجموعُ الحركات
 *
 * لا عمود رصيد يُصحَّح فيفترق عن دفتره. الموجب تراكمٌ والسالب تصفية، والجمع
 * وحده يعطي ما على الشركة.
 */
class EndOfServiceService
{
    /** حساب المخصّص (التزام) — يُطفَأ عند التصفية. */
    public const PROVISION_ACCOUNT = '2210';

    /** حساب المصروف — يُحمَّل بالتراكم الشهريّ وحده. */
    public const EXPENSE_ACCOUNT = '5210';

    public function __construct(private readonly VoucherService $vouchers) {}

    /**
     * تراكم شهرٍ واحد لموظف — راتبه الأساسيّ الساري ÷ ١٢.
     *
     * ومن انتهت خدمته لا يتراكم له: لا عملَ في الشهر فلا التزام جديد.
     */
    public function monthlyAccrual(EmployeeProfile $profile, float $basicSalary): float
    {
        if ($profile->status !== 'active') {
            return 0.0;
        }

        return round($basicSalary / 12, 2);
    }

    /** رصيد المخصّص لموظف — مجموع حركاته. */
    public function balance(EmployeeProfile $profile): float
    {
        return round((float) EndOfServiceEntry::where('employee_profile_id', $profile->id)->sum('amount'), 2);
    }

    /** أرصدة عدّة موظفين دفعةً واحدة — لتفادي استعلامٍ لكل صفٍّ في القائمة. */
    public function balances(array $profileIds): Collection
    {
        return EndOfServiceEntry::whereIn('employee_profile_id', $profileIds)
            ->selectRaw('employee_profile_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('employee_profile_id')
            ->pluck('total', 'employee_profile_id')
            ->map(fn ($v) => round((float) $v, 2));
    }

    /** تسجيل تراكم المسيّر لموظف — يُستدعى من `PayrollService` بعد الترحيل. */
    public function recordAccrual(EmployeeProfile $profile, PayrollRun $run, float $amount, ?User $actor = null): ?EndOfServiceEntry
    {
        if ($amount <= 0) {
            return null;
        }

        return EndOfServiceEntry::create([
            'employee_profile_id' => $profile->id,
            'kind' => 'accrual',
            'entry_date' => $run->periodEnd()->toDateString(),
            'amount' => round($amount, 2),
            'payroll_run_id' => $run->id,
            'note' => __('تراكم مسيّر :p', ['p' => $run->periodLabel()]),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * تصفية المخصّص نقدًا — سند صرف: مدين المخصّص / دائن الخزينة.
     *
     * لا يمرّ بالمصروف: المصروف حُمّل شهرًا بشهر، وتحميلُه ثانيةً يُظهر كلفةَ
     * سنواتٍ كلّها في الشهر الذي خرج فيه الموظف.
     */
    public function settle(
        EmployeeProfile $profile,
        float $amount,
        int $treasuryId,
        User $actor,
        ?string $note = null,
    ): EndOfServiceEntry {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('المبلغ يجب أن يكون أكبر من صفر.')]);
        }

        $balance = $this->balance($profile);

        // الصرف فوق المتراكم يجعل المخصّص مدينًا — التزامٌ بالسالب لا معنى له.
        // الفرق المستحقّ يُضاف بتسوية صريحة أوّلًا كي يبقى سببُه مكتوبًا.
        if ($amount > $balance) {
            throw ValidationException::withMessages([
                'amount' => __('المبلغ يتجاوز المخصّص المتراكم (:b). أضف تسويةً بالفارق أوّلًا.', ['b' => number_format($balance, 2)]),
            ]);
        }

        $account = Account::where('code', self::PROVISION_ACCOUNT)->firstOrFail();

        return DB::transaction(function () use ($profile, $amount, $treasuryId, $actor, $note, $account) {
            $voucher = $this->vouchers->create('payment', [
                'voucher_date' => now()->toDateString(),
                'treasury_id' => $treasuryId,
                'counter_account_id' => $account->id,
                'employee_id' => $profile->user_id,
                'party_name' => $profile->user?->name,
                'amount' => $amount,
                'category' => 'end_of_service',
                'description' => __('تصفية مكافأة نهاية الخدمة — :n', ['n' => $profile->user?->name]),
                'notes' => $note,
            ]);

            $this->vouchers->approve($voucher, $actor);
            $this->vouchers->post($voucher, $actor);

            return EndOfServiceEntry::create([
                'employee_profile_id' => $profile->id,
                'kind' => 'settlement',
                'entry_date' => now()->toDateString(),
                // سالبٌ لأن الرصيد مجموعُ الحركات: التصفية تُنقصه.
                'amount' => -$amount,
                'financial_voucher_id' => $voucher->id,
                'note' => $note ?: __('تصفية نقدية'),
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * تسوية يدوية — فرقُ تقديرٍ أو استحقاقٌ عن خدمةٍ سابقة لتشغيل النظام.
     *
     * لا تُنشئ قيدًا وحدها: من يُدخل رصيدًا افتتاحيًّا للمخصّص يُقيّده سندًا
     * محاسبيًّا مستقلًّا. وهذا السطر يجعل الدفتر يوافقه.
     */
    public function adjust(EmployeeProfile $profile, float $amount, User $actor, ?string $note = null): EndOfServiceEntry
    {
        if (round($amount, 2) == 0.0) {
            throw ValidationException::withMessages(['amount' => __('المبلغ لا يكون صفرًا.')]);
        }

        return EndOfServiceEntry::create([
            'employee_profile_id' => $profile->id,
            'kind' => 'adjustment',
            'entry_date' => now()->toDateString(),
            'amount' => round($amount, 2),
            'note' => $note,
            'created_by' => $actor->id,
        ]);
    }
}
