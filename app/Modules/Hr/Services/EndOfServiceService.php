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
 * والتراكم يُقيَّد مع كل كشف: مدين «مصروف مكافأة نهاية الخدمة» / دائن
 * «مخصّص مكافأة نهاية الخدمة». والتصفية تُطفئ المخصّص من الخزينة ولا تمرّ
 * بالمصروف ثانيةً — وإلّا حُمّلت السنةُ الأخيرة كلفةَ كل السنوات.
 *
 * ## والرصيد مجموعُ الحركات
 *
 * لا عمود رصيد يُصحَّح فيفترق عن دفتره. الموجب تراكمٌ والسالب تصفية، والجمع
 * وحده يعطي ما على الشركة.
 *
 * ## والصرف نهاية السنة — يدويًّا
 *
 * التراكم شهريّ لأنه التزامٌ ينشأ بالعمل، أمّا **الصرف فمرّةً في نهاية السنة**
 * من شاشة «صرف نهاية الخدمة». ولا يصرف النظام من تلقاء نفسه ولا في موعدٍ
 * مُبرمَج: يُسلَّم المبلغ باليد ثم يُسجَّل هنا، فيُقيَّد سندُ الصرف ويُطفأ
 * المخصّص بقيمته. صرفٌ آليّ يُخرج نقدًا لم يُسلَّم، ويجعل الدفتر يقول ما لم
 * يحدث.
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
     *
     * **والدوام الكامل وحده يستحقّ.** العقد والدوام الجزئيّ أجرٌ مقابل عمل بلا
     * مكافأة نهاية خدمة، فتراكمُها لهما يُنشئ في الميزانية التزامًا لا يقوم
     * على اتفاق.
     */
    public function monthlyAccrual(EmployeeProfile $profile, float $basicSalary): float
    {
        if ($profile->status !== 'active' || ! $profile->accruesBenefits()) {
            return 0.0;
        }

        return round($basicSalary / 12, 2);
    }

    /** رصيد المخصّص لموظف — مجموع حركاته. */
    public function balance(EmployeeProfile $profile): float
    {
        return round((float) $this->balances([$profile->id])->get($profile->id, 0.0), 2);
    }

    /**
     * أرصدة عدّة موظفين دفعةً واحدة — لتفادي استعلامٍ لكل صفٍّ في القائمة.
     *
     * والجمع في PHP لا في SQL: مبلغُ الحركة يُقرأ من سندها حيث وُجد
     * (`EndOfServiceEntry::effectiveAmount`)، والحركاتُ قليلةٌ بطبعها — سطرٌ
     * لكل موظفٍ في كل شهر.
     */
    public function balances(array $profileIds): Collection
    {
        return $this->entriesFor($profileIds)
            ->groupBy('employee_profile_id')
            ->map(fn (Collection $entries) => round(
                (float) $entries->sum(fn (EndOfServiceEntry $e) => $e->effectiveAmount()), 2,
            ));
    }

    /**
     * حركاتُ موظفين بسنداتها.
     *
     * @param  array<int, int>  $profileIds
     * @return Collection<int, EndOfServiceEntry>
     */
    private function entriesFor(array $profileIds): Collection
    {
        if ($profileIds === []) {
            return collect();
        }

        return EndOfServiceEntry::whereIn('employee_profile_id', $profileIds)
            ->with('voucher:id,status,amount')
            ->get();
    }

    /**
     * صفوف شاشة صرف نهاية السنة — لكل موظف: متراكمُ السنة، ومصروفُها، والرصيد.
     *
     * الرصيد يشمل سنواتٍ سابقة لم تُصرف، فهو وحده ما يُصرف — لا متراكمُ السنة.
     * وعرضُهما معًا يُري الفرق بدل أن يُخفيه.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function yearEndRows(int $year): Collection
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        $profiles = EmployeeProfile::with(['user:id,name,job_title', 'branch:id,name'])->get();

        $entries = $this->entriesFor($profiles->pluck('id')->all())->groupBy('employee_profile_id');

        // المبلغ من السند حيث وُجد: تعديلُ سندٍ بعد صرفه يُغيّر ما صُرف فعلًا،
        // وعكسُه يُلغيه — والنسخة المحفوظة في الحركة لا تعرف ذلك.
        $sum = fn (Collection $rows, callable $keep) => round(
            (float) $rows->map(fn (EndOfServiceEntry $e) => $e->effectiveAmount())
                ->filter($keep)->sum(), 2,
        );

        return $profiles
            ->map(function (EmployeeProfile $p) use ($entries, $from, $to, $sum) {
                $all = $entries->get($p->id, collect());
                $inYear = $all->filter(fn (EndOfServiceEntry $e) => $e->entry_date->between($from, $to));

                return [
                    'profile' => $p,
                    'balance' => $sum($all, fn () => true),
                    'accrued' => $sum($inYear, fn (float $v) => $v > 0),
                    // `abs` لا سالبٌ مقلوب: الصفر السالب يُطبع «‎-0.00» في الشاشة.
                    'settled' => abs($sum($inYear, fn (float $v) => $v < 0)),
                ];
            })
            // من لا رصيد له ولا حركة في السنة لا شأن له بهذه الشاشة.
            ->filter(fn (array $r) => $r['balance'] != 0.0 || $r['accrued'] != 0.0 || $r['settled'] != 0.0)
            ->sortBy(fn (array $r) => $r['profile']->user?->name)
            ->values();
    }

    /**
     * صرف نهاية السنة لعدّة موظفين — سندُ صرفٍ لكلٍّ منهم.
     *
     * **يُتحقَّق من الجميع قبل صرف أحد.** دفعةٌ تُصرف نصفَها ثم تتوقّف على خطأ
     * تترك الخزينة مصروفةً بعضًا والشاشة تقول «فشل»، فيُعاد الإرسال فيُصرف
     * المصروفُ مرّتين. فإمّا أن تمرّ كلّها أو لا شيء.
     *
     * وسندٌ لكلٍّ لا سندٌ جامع: كلٌّ يقبض مبلغه ويوقّع عليه، وسندٌ واحد يُخفي
     * مَن قبض ومَن لم يقبض.
     *
     * @param  array<int, float>  $amounts  معرّف ملفّ الموظف ⇦ المبلغ
     * @return int عدد من صُرف لهم
     */
    public function settleMany(array $amounts, int $treasuryId, User $actor, ?string $note = null): int
    {
        $amounts = collect($amounts)
            ->map(fn ($v) => round((float) $v, 2))
            ->filter(fn (float $v) => $v > 0);

        if ($amounts->isEmpty()) {
            throw ValidationException::withMessages([
                'amounts' => __('لم يُحدَّد أحد — اختر موظفًا وأدخل مبلغًا أكبر من صفر.'),
            ]);
        }

        $profiles = EmployeeProfile::with('user:id,name')
            ->whereIn('id', $amounts->keys()->all())->get()->keyBy('id');

        $balances = $this->balances($profiles->keys()->all());

        $errors = [];

        foreach ($amounts as $profileId => $amount) {
            $profile = $profiles->get($profileId);

            if (! $profile) {
                $errors['amounts'][] = __('موظفٌ غير موجود في القائمة.');

                continue;
            }

            $balance = (float) $balances->get($profileId, 0.0);

            if ($amount > $balance) {
                $errors['amounts.'.$profileId][] = __(':n — المبلغ يتجاوز المخصّص المتراكم (:b).', [
                    'n' => $profile->user?->name,
                    'b' => number_format($balance, 2),
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($amounts, $profiles, $treasuryId, $actor, $note) {
            foreach ($amounts as $profileId => $amount) {
                $this->settle($profiles->get($profileId), $amount, $treasuryId, $actor, $note);
            }

            return $amounts->count();
        });
    }

    /** تسجيل تراكم الكشف لموظف — يُستدعى من `PayrollService` بعد الترحيل. */
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
            'note' => __('تراكم كشف :p', ['p' => $run->periodLabel()]),
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
