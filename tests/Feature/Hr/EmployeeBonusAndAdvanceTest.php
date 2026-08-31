<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\EmployeeSalary;
use App\Modules\Hr\Services\EmployeeFinanceService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Reporting\Services\ProfitLossService;
use App\Modules\Reporting\Support\DateRange;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * المكافآت والسلف — خارج العقد، وبحسابين مختلفين.
 *
 * ## الفرق الذي تحرسه هذه الاختبارات
 *
 * **المكافأة مصروف**: خرج المال ولن يعود. **والسلفة أصل**: خرج المال وهو
 * دَينٌ على الموظف. وقيدُ السلفة مصروفًا هو الخطأ الشائع — يُضخّم كلفة
 * الشهر، ويُخفي أصلًا للشركة، ثم يُقرأ التسديد إيرادًا فيظهر ربحٌ من إقراض
 * الموظفين.
 *
 * ## ولا يمسّان الراتب الثابت
 *
 * مكافأةٌ تُضاف إلى العقد تصير راتبًا دائمًا يتكرّر كل شهر، ويتضخّم معها
 * مخصّص نهاية الخدمة (الأساسيّ ÷ ١٢) فيصير التزامًا عن مبلغٍ مُنِح مرّةً.
 */
class EmployeeBonusAndAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private EmployeeProfile $employee;

    protected function setUp(): void
    {
        // وقتٌ مثبَّت في وسط الشهر: التقارير تُحدّ الفترة بتاريخٍ لا وقت،
        // وحركةٌ في آخر يومٍ من الشهر تقع على الحدّ فيختلف احتسابها بين
        // SQLite (الاختبارات) وMySQL (الإنتاج). والوسطُ يقيس ما نريد قياسه.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);

        $this->employee = EmployeeProfile::create([
            'user_id' => User::factory()->create(['name' => 'طه'])->id,
            'branch_id' => Branch::default()->id,
            'hire_date' => '2026-01-01',
            'status' => 'active',
            'employment_type' => 'full_time',
            'annual_leave_days' => 14,
            'created_by' => $this->admin->id,
        ]);

        EmployeeSalary::create([
            'employee_profile_id' => $this->employee->id,
            'effective_from' => '2026-01-01',
            'basic_salary' => 2500,
            'allowances' => 0,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): EmployeeFinanceService
    {
        return app(EmployeeFinanceService::class);
    }

    private function treasury(): Treasury
    {
        return Treasury::where('code', 'CB-MAIN')->firstOrFail();
    }

    private function balance(string $code): float
    {
        return round(app(AccountingService::class)->accountBalance(
            Account::where('code', $code)->firstOrFail(),
        ), 2);
    }

    // ────────── المكافأة مصروف ──────────

    /** **المكافأة مصروفٌ يخرج من الخزينة** — مدين ٥٢٢٠ / دائن الخزينة. */
    public function test_a_bonus_is_an_expense_paid_from_the_treasury(): void
    {
        $cash = $this->balance('1011-0001');

        $this->service()->grantBonus($this->employee, 300, $this->treasury()->id, $this->admin);

        $this->assertSame(300.0, $this->balance('5220'));
        $this->assertSame(round($cash - 300, 2), $this->balance('1011-0001'));
    }

    /** **ولا تُغيّر الراتب الثابت** — العقد كما هو، فالكشف التالي كما كان. */
    public function test_a_bonus_does_not_touch_the_contract_salary(): void
    {
        $this->service()->grantBonus($this->employee, 300, $this->treasury()->id, $this->admin);

        $salary = $this->employee->salaryOn('2026-08-31');
        $this->assertSame('2500.00', $salary->basic_salary);

        $payroll = app(PayrollService::class);
        $run = $payroll->generate(2026, 8, $this->admin);

        $this->assertSame('2500.00', $run->lines()->first()->basic_salary);
        $this->assertSame('2500.00', $run->total_net);
    }

    /**
     * **ولا يتراكم عليها مخصّص نهاية الخدمة.**
     *
     * المخصّص الأساسيّ ÷ ١٢، فمكافأةٌ تدخله تُنشئ التزامًا على الشركة عن مبلغٍ
     * مُنِح مرّةً واحدة.
     */
    public function test_a_bonus_does_not_accrue_end_of_service(): void
    {
        $this->service()->grantBonus($this->employee, 1200, $this->treasury()->id, $this->admin);

        $run = app(PayrollService::class)->generate(2026, 8, $this->admin);

        // ٢٥٠٠ ÷ ١٢ = ٢٠٨٫٣٣ — لا ٣٠٨٫٣٣.
        $this->assertSame('208.33', $run->lines()->first()->eos_provision);
    }

    /** وتظهر في قائمة الدخل مصروفًا. */
    public function test_a_bonus_shows_in_the_profit_and_loss(): void
    {
        $this->service()->grantBonus($this->employee, 300, $this->treasury()->id, $this->admin);

        $report = app(ProfitLossService::class)->report(DateRange::resolve('this_month'));

        $this->assertSame(300.0, $report['expenses']['bonuses']);
    }

    // ────────── السلفة أصل ──────────

    /** **السلفة أصلٌ لا مصروف** — مدين ١١٥٠ / دائن الخزينة. */
    public function test_an_advance_is_an_asset_not_an_expense(): void
    {
        $cash = $this->balance('1011-0001');

        $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);

        $this->assertSame(500.0, $this->balance('1150'));
        $this->assertSame(0.0, $this->balance('5220'));
        $this->assertSame(round($cash - 500, 2), $this->balance('1011-0001'));
        $this->assertSame(500.0, $this->service()->advanceBalance($this->employee));
    }

    /** **ولا تدخل قائمة الدخل** — مالٌ عند الموظف يعود، لا كلفةُ فترة. */
    public function test_an_advance_never_reaches_the_profit_and_loss(): void
    {
        $before = app(ProfitLossService::class)->report(DateRange::resolve('this_month'));

        $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);
        $after = app(ProfitLossService::class)->report(DateRange::resolve('this_month'));

        $this->assertSame($before['expenses']['total'], $after['expenses']['total']);
        $this->assertSame($before['net_income'], $after['net_income']);
    }

    /** **والتسديد يُطفئ الدَّين ولا يُنشئ إيرادًا.** */
    public function test_a_repayment_clears_the_debt_without_creating_revenue(): void
    {
        $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);
        $revenue = $this->balance('4010');
        $cash = $this->balance('1011-0001');

        $this->service()->repayAdvance($this->employee, 200, $this->treasury()->id, $this->admin);

        $this->assertSame(300.0, $this->service()->advanceBalance($this->employee));
        $this->assertSame(300.0, $this->balance('1150'));
        $this->assertSame($revenue, $this->balance('4010'));
        $this->assertSame(round($cash + 200, 2), $this->balance('1011-0001'));
    }

    /** وتسديدٌ فوق القائم يُرفض — أصلٌ بالسالب لا معنى له. */
    public function test_repaying_more_than_outstanding_is_refused(): void
    {
        $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);

        $this->expectException(ValidationException::class);
        $this->service()->repayAdvance($this->employee, 600, $this->treasury()->id, $this->admin);
    }

    /** ولا تُغيّر السلفة الراتب الثابت ولا الكشف. */
    public function test_an_advance_does_not_touch_the_payroll(): void
    {
        $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);

        $run = app(PayrollService::class)->generate(2026, 8, $this->admin);

        $this->assertSame('2500.00', $run->total_net);
        $this->assertSame('0.00', $run->lines()->first()->other_deductions);
    }

    // ────────── الحركة تتبع سندها ──────────

    /** **وعكسُ سند السلفة يُلغي الدَّين** — ماله عاد إلى الخزينة. */
    public function test_reversing_an_advance_voucher_clears_the_debt(): void
    {
        $entry = $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);

        app(VoucherService::class)->reverse($entry->voucher()->first());

        $this->assertSame(0.0, $this->service()->advanceBalance($this->employee));
    }

    /** وتعديل السند يُصحّح الرصيد. */
    public function test_editing_the_voucher_corrects_the_balance(): void
    {
        $entry = $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);

        app(VoucherService::class)->repost($entry->voucher()->first(), ['amount' => 350], $this->admin);

        $this->assertSame(350.0, $this->service()->advanceBalance($this->employee));
    }

    // ────────── الشاشة والصلاحية ──────────

    /** الملفّ يعرض النموذج والدفتر. */
    public function test_the_employee_page_shows_the_ledger(): void
    {
        $this->service()->grantAdvance($this->employee, 500, $this->treasury()->id, $this->admin);

        $this->get(route('admin.hr.employees.show', $this->employee))
            ->assertOk()
            ->assertSee('المكافآت والسلف')
            ->assertSee('السلفة القائمة')
            ->assertSee('500.00');
    }

    /** والتسجيل من الشاشة يعمل لكل نوع. */
    public function test_recording_from_the_screen_works(): void
    {
        foreach ([['bonus', 300], ['advance', 500], ['advance_repayment', 200]] as [$kind, $amount]) {
            $this->post(route('admin.hr.employees.finance.store', $this->employee), [
                'kind' => $kind, 'amount' => $amount, 'treasury_id' => $this->treasury()->id,
            ])->assertRedirect()->assertSessionHas('success');
        }

        $this->assertSame(300.0, $this->service()->advanceBalance($this->employee));
        $this->assertSame(300.0, $this->balance('5220'));
        $this->assertSame(3, FinancialVoucher::whereIn('category', ['bonus', 'advance', 'advance_repayment'])->count());
    }

    /** **ومن لا يملك الإدارة لا يُسجّل.** */
    public function test_a_viewer_cannot_record(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['hr.employees.view', 'hr.payroll.view']);

        $this->actingAs($viewer)
            ->post(route('admin.hr.employees.finance.store', $this->employee), [
                'kind' => 'advance', 'amount' => 500, 'treasury_id' => $this->treasury()->id,
            ])->assertForbidden();

        $this->assertSame(0.0, $this->service()->advanceBalance($this->employee));
    }
}
