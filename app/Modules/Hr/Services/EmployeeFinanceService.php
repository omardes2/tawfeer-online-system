<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Hr\Models\EmployeeFinanceEntry;
use App\Modules\Hr\Models\EmployeeProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المكافآت والسلف — مالٌ يخرج للموظف خارج راتبه.
 *
 * ## لا يمسّان العقد
 *
 * الراتب رقمٌ في عقدٍ ساري، وكشوفُ الرواتب تُبنى منه. فمكافأةٌ تُضاف إليه
 * تصير راتبًا دائمًا يتكرّر كل شهر، ويتضخّم معها مخصّص نهاية الخدمة (الأساسيّ
 * ÷ ١٢) فيصير التزامًا على الشركة عن مبلغٍ مُنِح مرّةً واحدة. والسلفة أسوأ:
 * زيادتُها للراتب تجعل الدَّين أجرًا لا يُستردّ.
 *
 * فكلاهما حدثٌ مستقلّ بسندٍ خاصّ، والعقد يبقى كما هو.
 *
 * ## والفرق بينهما محاسبيّ لا شكليّ
 *
 * ```
 * المكافأة:      مدين  ٥٢٢٠ مكافآت وحوافز        / دائن الخزينة
 * السلفة:        مدين  ١١٥٠ سلف الموظفين         / دائن الخزينة
 * تسديد السلفة:  مدين  الخزينة                   / دائن ١١٥٠ سلف الموظفين
 * ```
 *
 * **المكافأة مصروف** — خرج المال ولن يعود. **والسلفة أصل** — خرج المال وهو
 * دَينٌ على الموظف. وقيدُ السلفة مصروفًا هو الخطأ الشائع: يُضخّم كلفة الشهر،
 * ويُخفي أصلًا للشركة، ثم يُقرأ التسديد إيرادًا — فيظهر ربحٌ من إقراض
 * الموظفين.
 *
 * ## والصرف يدويّ
 *
 * كسائر ما في هذا النظام: المبلغ يُسلَّم باليد ثم يُسجَّل، فيُقيَّد السند
 * ويخرج من الخزينة المختارة. لا موعد مُبرمَج ولا تحويل يُرسله النظام.
 */
class EmployeeFinanceService
{
    /** سلف الموظفين — **أصل** لا مصروف. */
    public const ADVANCE_ACCOUNT = '1150';

    /** المكافآت والحوافز — مصروفُ شهرها. */
    public const BONUS_ACCOUNT = '5220';

    public function __construct(private readonly VoucherService $vouchers) {}

    // ————————————————————————— المنح —————————————————————————

    /** مكافأة تُصرف نقدًا — مصروفُ شهرها، ولا تدخل العقد. */
    public function grantBonus(
        EmployeeProfile $profile,
        float $amount,
        int $treasuryId,
        User $actor,
        ?string $note = null,
    ): EmployeeFinanceEntry {
        return $this->record($profile, 'bonus', $amount, $treasuryId, $actor, $note);
    }

    /** سلفة تُصرف نقدًا — دَينٌ على الموظف يُستردّ. */
    public function grantAdvance(
        EmployeeProfile $profile,
        float $amount,
        int $treasuryId,
        User $actor,
        ?string $note = null,
    ): EmployeeFinanceEntry {
        return $this->record($profile, 'advance', $amount, $treasuryId, $actor, $note);
    }

    /**
     * تسديد سلفة — يُطفئ الدَّين ولا يُنشئ إيرادًا.
     *
     * ولا يتجاوز القائم: تسديدٌ فوق الدَّين يجعل «سلف الموظفين» دائنًا، أي
     * أصلًا بالسالب — وهو في الحقيقة دَينٌ على الشركة للموظف لا سلفة.
     */
    public function repayAdvance(
        EmployeeProfile $profile,
        float $amount,
        int $treasuryId,
        User $actor,
        ?string $note = null,
    ): EmployeeFinanceEntry {
        $outstanding = $this->advanceBalance($profile);

        if (round($amount, 2) > $outstanding) {
            throw ValidationException::withMessages([
                'amount' => __('المبلغ يتجاوز السلفة القائمة (:b).', ['b' => number_format($outstanding, 2)]),
            ]);
        }

        return $this->record($profile, 'advance_repayment', $amount, $treasuryId, $actor, $note);
    }

    /**
     * سندٌ واحد وحركةٌ واحدة في معاملةٍ واحدة.
     *
     * والاعتماد والترحيل فوريان: المال خرج فعلًا من الدُّرج لحظة الضغط، وسندٌ
     * مسودّة يترك الخزينة في الدفتر أكبر ممّا هي.
     */
    private function record(
        EmployeeProfile $profile,
        string $kind,
        float $amount,
        int $treasuryId,
        User $actor,
        ?string $note,
    ): EmployeeFinanceEntry {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('المبلغ يجب أن يكون أكبر من صفر.')]);
        }

        [$code, $voucherKind, $label] = match ($kind) {
            'bonus' => [self::BONUS_ACCOUNT, 'payment', __('مكافأة')],
            'advance' => [self::ADVANCE_ACCOUNT, 'payment', __('سلفة')],
            'advance_repayment' => [self::ADVANCE_ACCOUNT, 'receipt', __('تسديد سلفة')],
        };

        $account = Account::where('code', $code)->firstOrFail();

        return DB::transaction(function () use ($profile, $kind, $amount, $treasuryId, $actor, $note, $account, $voucherKind, $label) {
            $voucher = $this->vouchers->create($voucherKind, [
                'voucher_date' => now()->toDateString(),
                'treasury_id' => $treasuryId,
                'counter_account_id' => $account->id,
                'employee_id' => $profile->user_id,
                'party_name' => $profile->user?->name,
                'amount' => $amount,
                'category' => $kind,
                'description' => $label.' — '.$profile->user?->name,
                'notes' => $note,
            ]);

            $this->vouchers->approve($voucher, $actor);
            $this->vouchers->post($voucher, $actor);

            return EmployeeFinanceEntry::create([
                'employee_profile_id' => $profile->id,
                'kind' => $kind,
                'entry_date' => now()->toDateString(),
                'amount' => $amount,
                'financial_voucher_id' => $voucher->id,
                'note' => $note,
                'created_by' => $actor->id,
            ]);
        });
    }

    // ————————————————————————— الأرصدة —————————————————————————

    /** السلفة القائمة على موظف — ما مُنِح ناقص ما سُدّد. */
    public function advanceBalance(EmployeeProfile $profile): float
    {
        return round((float) $this->advanceBalances([$profile->id])->get($profile->id, 0.0), 2);
    }

    /**
     * سلف عدّة موظفين دفعةً واحدة — لتفادي استعلامٍ لكل صفٍّ في القائمة.
     *
     * @param  array<int, int>  $profileIds
     * @return Collection<int, float>
     */
    public function advanceBalances(array $profileIds): Collection
    {
        return $this->entriesFor($profileIds)
            ->groupBy('employee_profile_id')
            ->map(fn (Collection $rows) => round(
                (float) $rows->sum(fn (EmployeeFinanceEntry $e) => $e->advanceEffect()), 2,
            ))
            ->filter(fn (float $v) => $v != 0.0);
    }

    /** مجموع مكافآت موظف في سنة — أو في عمره كلّه حين تُترك السنة فارغة. */
    public function bonusTotal(EmployeeProfile $profile, ?int $year = null): float
    {
        return round((float) $this->entriesFor([$profile->id])
            ->where('kind', 'bonus')
            ->when($year !== null, fn (Collection $rows) => $rows->filter(
                fn (EmployeeFinanceEntry $e) => (int) $e->entry_date->year === $year,
            ))
            ->sum(fn (EmployeeFinanceEntry $e) => $e->effectiveAmount()), 2);
    }

    /**
     * دفتر الموظف كاملًا — الأحدث أوّلًا.
     *
     * @return Collection<int, EmployeeFinanceEntry>
     */
    public function ledger(EmployeeProfile $profile): Collection
    {
        return $this->entriesFor([$profile->id])
            ->sortByDesc(fn (EmployeeFinanceEntry $e) => [$e->entry_date->timestamp, $e->id])
            ->values();
    }

    /**
     * حركاتُ موظفين بسنداتها.
     *
     * والجمع في PHP لا في SQL: المبلغ يُقرأ من السند حيث وُجد
     * (`EmployeeFinanceEntry::effectiveAmount`)، والحركاتُ قليلةٌ بطبعها.
     *
     * @param  array<int, int>  $profileIds
     * @return Collection<int, EmployeeFinanceEntry>
     */
    private function entriesFor(array $profileIds): Collection
    {
        if ($profileIds === []) {
            return collect();
        }

        return EmployeeFinanceEntry::whereIn('employee_profile_id', $profileIds)
            ->with('voucher:id,uuid,number,status,amount,kind')
            ->get();
    }
}
