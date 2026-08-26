<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\EndOfServiceService;
use App\Modules\Hr\Services\PayrollService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * مسيّر الرواتب — من العقد إلى القيد إلى الصرف.
 *
 * والفحص الحاسم ليس «هل ظهر الراتب؟» بل **«هل الدفتر متماسك؟»**: القيد
 * متوازن، والمصروف هو الصافي لا الاستحقاق، والالتزام يُطفأ بالصرف لا قبله،
 * ولا يُقيَّد شيءٌ مرّتين.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Treasury $treasury;

    protected function setUp(): void
    {
        // قبل البذر لا بعده: المسيّرات تُولَّد لأشهر ٢٠٢٦، والسنة المالية
        // تُبذَر على «الآن». فتثبيتُ الوقت بعد البذر يترك قيودًا بلا سنةٍ ماليّة.
        Carbon::setTestNow(Carbon::parse('2026-12-15 10:00:00'));

        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->treasury = Treasury::firstOrFail();
    }

    /** موظفٌ براتبٍ ساري منذ بداية العام الماضي. */
    private function employee(float $basic = 3000, float $allowances = 0, string $hire = '2025-01-01'): EmployeeProfile
    {
        $user = User::factory()->create(['name' => 'موظف '.uniqid()]);

        $profile = EmployeeProfile::create([
            'user_id' => $user->id,
            'hire_date' => $hire,
            'status' => 'active',
            'annual_leave_days' => 14,
            'created_by' => $this->admin->id,
        ]);

        $profile->salaries()->create([
            'effective_from' => $hire,
            'basic_salary' => $basic,
            'allowances' => $allowances,
        ]);

        return $profile->refresh();
    }

    private function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function generate(int $year = 2026, int $month = 1): PayrollRun
    {
        return $this->service()->generate($year, $month, $this->admin);
    }

    /** رصيد حسابٍ من القيود — لا رقمَ محفوظًا يُصدَّق. */
    private function accountBalance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        return round((float) JournalLine::where('account_id', $account->id)->sum('debit')
            - (float) JournalLine::where('account_id', $account->id)->sum('credit'), 2);
    }

    // ────────── التوليد ──────────

    /** المسيّر يُولَّد من العقود السارية. */
    public function test_it_builds_a_line_per_employee_with_a_salary(): void
    {
        $this->employee(basic: 3000);
        $this->employee(basic: 2000);

        $run = $this->generate();

        $this->assertSame('draft', $run->status);
        $this->assertCount(2, $run->lines);
        $this->assertEqualsWithDelta(5000.0, (float) $run->total_net, 0.01);
    }

    /** ومن بلا راتبٍ مسجَّل لا يدخل — ولا يُخترع له صفر. */
    public function test_an_employee_without_a_salary_is_skipped(): void
    {
        $this->employee(basic: 3000);

        $user = User::factory()->create();
        EmployeeProfile::create([
            'user_id' => $user->id, 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);

        $this->assertCount(1, $this->generate()->lines);
    }

    /** ومن عُيّن بعد الشهر لم يعمل فيه. */
    public function test_someone_hired_after_the_period_is_not_included(): void
    {
        $this->employee(basic: 3000, hire: '2026-05-01');

        $this->assertCount(0, $this->generate(2026, 1)->lines);
    }

    /** والراتب المُطبَّق هو الساري في نهاية الشهر لا الأحدث مطلقًا. */
    public function test_the_salary_in_effect_for_the_period_is_used(): void
    {
        $profile = $this->employee(basic: 3000, hire: '2025-01-01');
        $profile->salaries()->create(['effective_from' => '2026-06-01', 'basic_salary' => 5000]);

        $this->assertEqualsWithDelta(3000.0, (float) $this->generate(2026, 1)->total_net, 0.01);
        $this->assertEqualsWithDelta(5000.0, (float) $this->generate(2026, 7)->total_net, 0.01);
    }

    /** وإعادة التوليد تُعيد بناء المسودّة لا تُضاعفها. */
    public function test_regenerating_a_draft_replaces_its_lines(): void
    {
        $this->employee(basic: 3000);

        $this->generate();
        $run = $this->generate();

        $this->assertCount(1, $run->lines);
    }

    /** والمُرحَّل لا يُعاد توليده. */
    public function test_a_posted_run_cannot_be_regenerated(): void
    {
        $this->employee(basic: 3000);
        $this->service()->post($this->generate(), $this->admin);

        $this->expectException(ValidationException::class);
        $this->generate();
    }

    // ────────── الإجازة بلا راتب ──────────

    /**
     * **الإجازة بلا راتب تُخصَم من مسيّر شهرها.**
     *
     * ثلاثون يومًا قاسمًا لا عددُ أيام الشهر — وإلا اختلفت قيمة اليوم بين
     * شباط وآذار للراتب نفسه.
     */
    public function test_unpaid_leave_is_deducted_from_its_own_month(): void
    {
        $profile = $this->employee(basic: 3000);

        $profile->leaves()->create([
            'kind' => 'unpaid', 'from_date' => '2026-01-10', 'to_date' => '2026-01-12', 'days' => 3,
        ]);

        $line = $this->generate(2026, 1)->lines->first();

        $this->assertEqualsWithDelta(300.0, (float) $line->unpaid_leave_amount, 0.01);
        $this->assertEqualsWithDelta(2700.0, (float) $line->net, 0.01);

        // وشهرٌ آخر لا يتأثّر.
        $this->assertEqualsWithDelta(3000.0, (float) $this->generate(2026, 2)->total_net, 0.01);
    }

    /** والإجازة السنوية لا تمسّ الراتب — الموظف يقبض كاملًا. */
    public function test_annual_leave_does_not_touch_the_salary(): void
    {
        $profile = $this->employee(basic: 3000);

        $profile->leaves()->create([
            'kind' => 'annual', 'from_date' => '2026-01-05', 'to_date' => '2026-01-09', 'days' => 5,
        ]);

        $this->assertEqualsWithDelta(3000.0, (float) $this->generate(2026, 1)->total_net, 0.01);
    }

    /** والخصم لا يتجاوز الاستحقاق — راتبٌ بالسالب ليس دَينًا على الموظف. */
    public function test_the_deduction_never_exceeds_the_salary(): void
    {
        $profile = $this->employee(basic: 3000);

        $profile->leaves()->create([
            'kind' => 'unpaid', 'from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'days' => 60,
        ]);

        $this->assertEqualsWithDelta(0.0, (float) $this->generate(2026, 1)->lines->first()->net, 0.01);
    }

    // ────────── الترحيل ──────────

    /** الترحيل يُثبت المصروف والالتزام معًا. */
    public function test_posting_debits_the_expense_and_credits_the_payable(): void
    {
        $this->employee(basic: 3000);

        $this->service()->post($this->generate(), $this->admin);

        $this->assertEqualsWithDelta(3000.0, $this->accountBalance(PayrollService::SALARY_EXPENSE_ACCOUNT), 0.01);
        // الالتزام دائن، فرصيدُه (مدين − دائن) بالسالب.
        $this->assertEqualsWithDelta(-3000.0, $this->accountBalance(PayrollService::SALARY_PAYABLE_ACCOUNT), 0.01);
    }

    /**
     * **والمصروف هو الصافي لا الاستحقاق.**
     *
     * ما خُصم لم تستحقّه ذمّةُ الشركة أصلًا، فتحميلُه مصروفًا ثم تقييدُه
     * دائنًا لجهةٍ ما يخترع دَينًا لا وجود له.
     */
    public function test_the_expense_is_the_net_not_the_gross(): void
    {
        $profile = $this->employee(basic: 3000);
        $profile->leaves()->create([
            'kind' => 'unpaid', 'from_date' => '2026-01-10', 'to_date' => '2026-01-12', 'days' => 3,
        ]);

        $this->service()->post($this->generate(2026, 1), $this->admin);

        $this->assertEqualsWithDelta(2700.0, $this->accountBalance(PayrollService::SALARY_EXPENSE_ACCOUNT), 0.01);
    }

    /** والترحيل مرّة واحدة مهما أُعيد الطلب. */
    public function test_posting_twice_creates_one_entry(): void
    {
        $this->employee(basic: 3000);
        $run = $this->generate();

        $this->service()->post($run, $this->admin);
        $entryId = $run->fresh()->journal_entry_id;
        $this->service()->post($run->fresh(), $this->admin);

        $this->assertSame($entryId, $run->fresh()->journal_entry_id);
        $this->assertEqualsWithDelta(3000.0, $this->accountBalance(PayrollService::SALARY_EXPENSE_ACCOUNT), 0.01);
    }

    // ────────── مخصّص نهاية الخدمة ──────────

    /** التراكم شهرٌ عن كل سنة — أي الأساسيّ ÷ ١٢، والبدلات خارجه. */
    public function test_the_end_of_service_accrual_is_a_twelfth_of_the_basic(): void
    {
        $this->employee(basic: 3600, allowances: 1200);

        $run = $this->generate();

        $this->assertEqualsWithDelta(300.0, (float) $run->total_eos, 0.01);
    }

    /** وقيدُه مستقلّ عن قيد الرواتب. */
    public function test_it_posts_its_own_entry(): void
    {
        $profile = $this->employee(basic: 3600);

        $run = $this->service()->post($this->generate(), $this->admin);

        $this->assertNotNull($run->eos_journal_entry_id);
        $this->assertNotSame($run->journal_entry_id, $run->eos_journal_entry_id);
        $this->assertEqualsWithDelta(300.0, $this->accountBalance(EndOfServiceService::EXPENSE_ACCOUNT), 0.01);
        $this->assertEqualsWithDelta(-300.0, $this->accountBalance(EndOfServiceService::PROVISION_ACCOUNT), 0.01);
        $this->assertEqualsWithDelta(300.0, app(EndOfServiceService::class)->balance($profile), 0.01);
    }

    /**
     * **والتصفية لا تمرّ بالمصروف ثانيةً.**
     *
     * المصروف حُمّل شهرًا بشهر، وتحميلُه عند الخروج يُظهر كلفةَ سنواتٍ كلّها
     * في الشهر الأخير.
     */
    public function test_settling_clears_the_provision_without_touching_the_expense(): void
    {
        $profile = $this->employee(basic: 3600);
        $this->service()->post($this->generate(2026, 1), $this->admin);
        $this->service()->post($this->generate(2026, 2), $this->admin);

        $eos = app(EndOfServiceService::class);
        $this->assertEqualsWithDelta(600.0, $eos->balance($profile), 0.01);

        $eos->settle($profile, 600, $this->treasury->id, $this->admin);

        $this->assertEqualsWithDelta(0.0, $eos->balance($profile), 0.01);
        // المخصّص أُطفئ، والمصروف بقي على شهرين لا أكثر.
        $this->assertEqualsWithDelta(0.0, $this->accountBalance(EndOfServiceService::PROVISION_ACCOUNT), 0.01);
        $this->assertEqualsWithDelta(600.0, $this->accountBalance(EndOfServiceService::EXPENSE_ACCOUNT), 0.01);
    }

    /** والصرف فوق المتراكم مرفوض — التزامٌ بالسالب لا معنى له. */
    public function test_settling_more_than_the_provision_is_refused(): void
    {
        $profile = $this->employee(basic: 3600);
        $this->service()->post($this->generate(), $this->admin);

        $this->expectException(ValidationException::class);
        app(EndOfServiceService::class)->settle($profile, 9999, $this->treasury->id, $this->admin);
    }

    /** ومن انتهت خدمته لا يتراكم له. */
    public function test_no_accrual_for_an_ended_employee(): void
    {
        $profile = $this->employee(basic: 3600);
        $profile->update(['status' => 'ended', 'end_date' => '2025-12-31']);

        $this->assertEqualsWithDelta(0.0, app(EndOfServiceService::class)->monthlyAccrual($profile, 3600), 0.01);
    }

    // ────────── الصرف ──────────

    /** الصرف يُطفئ الالتزام ويُخرج النقدية — سندٌ لكل موظف. */
    public function test_paying_clears_the_payable_with_a_voucher_per_employee(): void
    {
        $this->employee(basic: 3000);
        $this->employee(basic: 2000);

        $run = $this->service()->post($this->generate(), $this->admin);

        $count = $this->service()->pay(
            $run, $run->lines->pluck('id')->all(), $this->treasury->id, $this->admin,
        );

        $this->assertSame(2, $count);
        $this->assertSame(2, FinancialVoucher::where('category', 'payroll')->where('status', 'posted')->count());
        // الالتزام أُثبت ٥٠٠٠ ثم أُطفئ ٥٠٠٠ — فرصيده صفر.
        $this->assertEqualsWithDelta(0.0, $this->accountBalance(PayrollService::SALARY_PAYABLE_ACCOUNT), 0.01);
        $this->assertSame('paid', $run->fresh()->status);
    }

    /** والمدفوع لا يُدفع ثانيةً ولو أُعيد الطلب. */
    public function test_a_paid_line_is_never_paid_twice(): void
    {
        $this->employee(basic: 3000);
        $run = $this->service()->post($this->generate(), $this->admin);
        $ids = $run->lines->pluck('id')->all();

        $this->service()->pay($run, $ids, $this->treasury->id, $this->admin);
        $second = $this->service()->pay($run->fresh(), $ids, $this->treasury->id, $this->admin);

        $this->assertSame(0, $second);
        $this->assertSame(1, FinancialVoucher::where('category', 'payroll')->count());
    }

    /** ولا يُصرف مسيّرٌ لم يُرحَّل — النقدية لا تخرج قبل إثبات الالتزام. */
    public function test_a_draft_run_cannot_be_paid(): void
    {
        $this->employee(basic: 3000);
        $run = $this->generate();

        $this->expectException(ValidationException::class);
        $this->service()->pay($run, $run->lines->pluck('id')->all(), $this->treasury->id, $this->admin);
    }

    /** وصرفُ بعضِ البنود يُبقي المسيّر «مُرحَّلًا» لا «مدفوعًا». */
    public function test_a_partial_payment_leaves_the_run_posted(): void
    {
        $this->employee(basic: 3000);
        $this->employee(basic: 2000);

        $run = $this->service()->post($this->generate(), $this->admin);

        $this->service()->pay($run, [$run->lines->first()->id], $this->treasury->id, $this->admin);

        $this->assertSame('posted', $run->fresh()->status);
        $this->assertEqualsWithDelta(2000.0, $run->fresh()->unpaidTotal(), 0.01);
    }

    // ────────── العكس ──────────

    /** العكس يُلغي أثر القيدين ويمحو تراكم المخصّص. */
    public function test_reversing_undoes_both_entries_and_the_provision(): void
    {
        $profile = $this->employee(basic: 3600);
        $run = $this->service()->post($this->generate(), $this->admin);

        $this->service()->reverse($run, $this->admin, 'خطأ إدخال');

        $this->assertSame('reversed', $run->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->accountBalance(PayrollService::SALARY_EXPENSE_ACCOUNT), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountBalance(PayrollService::SALARY_PAYABLE_ACCOUNT), 0.01);
        $this->assertEqualsWithDelta(0.0, app(EndOfServiceService::class)->balance($profile), 0.01);
    }

    /** ولا يُعكس مسيّرٌ صُرفت بنودُه — النقدية خرجت فعلًا. */
    public function test_a_run_with_paid_lines_cannot_be_reversed(): void
    {
        $this->employee(basic: 3000);
        $run = $this->service()->post($this->generate(), $this->admin);
        $this->service()->pay($run, $run->lines->pluck('id')->all(), $this->treasury->id, $this->admin);

        $this->expectException(ValidationException::class);
        $this->service()->reverse($run->fresh(), $this->admin);
    }

    // ────────── الشاشات ──────────

    /** الصفحات تفتح لمدير النظام. */
    public function test_the_admin_sees_the_screens(): void
    {
        $profile = $this->employee(basic: 3000);

        $this->actingAs($this->admin)->get(route('admin.hr.employees.index'))
            ->assertOk()->assertSee('الرواتب والموظفون');

        $this->actingAs($this->admin)->get(route('admin.hr.employees.show', $profile))
            ->assertOk()->assertSee('مكافأة نهاية الخدمة')->assertSee('العمولات');

        $this->actingAs($this->admin)->get(route('admin.hr.payroll.index'))->assertOk();
    }

    /** ولا تفتح لمن لا يملك صلاحيتها — الرواتب أرقامٌ شخصية. */
    public function test_a_seller_cannot_open_them(): void
    {
        $seller = User::factory()->create();
        $seller->assignRole('sales');

        $this->actingAs($seller)->get(route('admin.hr.employees.index'))->assertForbidden();
        $this->actingAs($seller)->get(route('admin.hr.payroll.index'))->assertForbidden();
    }

    /** والمسار الكامل من الشاشة: توليد فترحيل فصرف. */
    public function test_the_full_flow_through_the_screens(): void
    {
        $this->employee(basic: 3000);

        $this->actingAs($this->admin)
            ->post(route('admin.hr.payroll.generate'), ['period_year' => 2026, 'period_month' => 1])
            ->assertRedirect();

        $run = PayrollRun::firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.hr.payroll.post', $run))->assertRedirect();
        $this->assertSame('posted', $run->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.hr.payroll.pay', $run), [
            'treasury_id' => $this->treasury->id,
            'lines' => $run->fresh()->lines->pluck('id')->all(),
        ])->assertRedirect();

        $this->assertSame('paid', $run->fresh()->status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
