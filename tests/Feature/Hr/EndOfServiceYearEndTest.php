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
use App\Modules\Hr\Models\EndOfServiceEntry;
use App\Modules\Hr\Services\EndOfServiceService;
use App\Modules\Hr\Services\PayrollService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * مكافأة نهاية الخدمة: تراكمٌ شهريّ وصرفٌ يدويّ نهاية السنة.
 *
 * ## الفرق الذي تحرسه هذه الاختبارات
 *
 * المخصّص يُقيَّد كل شهر لأن الالتزام ينشأ بالعمل، لكنّ **النقدية لا تخرج**.
 * فمن يقرأ «مخصّص نهاية الخدمة ٣٧٥» في الكشف يظنّها مبلغًا يُدفع مع الراتب،
 * وهي دَينٌ يُثبَت في الدفتر ويُسلَّم مرّةً في نهاية السنة.
 *
 * ## والصرف يدويّ
 *
 * لا موعد مُبرمَج ولا تحويل يُرسله النظام: المبلغ يُسلَّم باليد ثم يُسجَّل،
 * فيُقيَّد سندُ صرفٍ لكل موظف. وصرفٌ آليّ يُخرج في الدفتر نقدًا لم يُسلَّم.
 */
class EndOfServiceYearEndTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private EmployeeProfile $taha;

    private EmployeeProfile $hadeel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);

        $this->taha = $this->employee('طه', 2500);
        $this->hadeel = $this->employee('هديل', 2000);
    }

    private function employee(string $name, float $basic): EmployeeProfile
    {
        $profile = EmployeeProfile::create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'branch_id' => Branch::default()->id,
            'hire_date' => '2026-01-01',
            'status' => 'active',
            'employment_type' => 'full_time',
            'annual_leave_days' => 14,
            'created_by' => $this->admin->id,
        ]);

        EmployeeSalary::create([
            'employee_profile_id' => $profile->id,
            'effective_from' => '2026-01-01',
            'basic_salary' => $basic,
            'allowances' => 0,
            'created_by' => $this->admin->id,
        ]);

        return $profile;
    }

    /** ترحيل كشف شهرٍ — يُنشئ التراكم. */
    private function postPayroll(int $month = 8): void
    {
        $payroll = app(PayrollService::class);
        $payroll->post($payroll->generate(2026, $month, $this->admin), $this->admin);
    }

    private function service(): EndOfServiceService
    {
        return app(EndOfServiceService::class);
    }

    private function treasury(): Treasury
    {
        return Treasury::where('code', 'CB-MAIN')->firstOrFail();
    }

    private function accountBalance(string $code): float
    {
        return round(app(AccountingService::class)->accountBalance(
            Account::where('code', $code)->firstOrFail(),
        ), 2);
    }

    // ────────── التراكم لا يُصرف مع الراتب ──────────

    /**
     * **الكشف يُقيّد المخصّص ولا يُخرج نقدًا.**
     *
     * هذا هو سوء الفهم الذي أثار الطلب: الرقم يظهر في الكشف فيُظنّ مصروفًا.
     */
    public function test_posting_a_payroll_provisions_without_paying(): void
    {
        $cashBefore = $this->accountBalance('1011-0001');

        $this->postPayroll();

        // ٢٥٠٠÷١٢ + ٢٠٠٠÷١٢ = ٢٠٨٫٣٣ + ١٦٦٫٦٧
        $this->assertSame(375.0, round($this->service()->balance($this->taha) + $this->service()->balance($this->hadeel), 2));
        $this->assertSame($cashBefore, $this->accountBalance('1011-0001'));
        $this->assertSame(0, FinancialVoucher::where('category', 'end_of_service')->count());
    }

    // ────────── الصرف اليدويّ نهاية السنة ──────────

    /** **الصرف يُخرج النقد ويُطفئ المخصّص** — سندٌ لكل موظف. */
    public function test_the_year_end_settlement_pays_and_clears_the_provision(): void
    {
        $this->postPayroll();
        $cashBefore = $this->accountBalance('1011-0001');

        $count = $this->service()->settleMany([
            $this->taha->id => 208.33,
            $this->hadeel->id => 166.67,
        ], $this->treasury()->id, $this->admin, 'صرف نهاية سنة ٢٠٢٦');

        $this->assertSame(2, $count);
        $this->assertSame(0.0, $this->service()->balance($this->taha));
        $this->assertSame(0.0, $this->service()->balance($this->hadeel));
        $this->assertSame(round($cashBefore - 375, 2), $this->accountBalance('1011-0001'));
        $this->assertSame(2, FinancialVoucher::where('category', 'end_of_service')->count());
    }

    /** **ولا يمرّ بالمصروف ثانيةً** — حُمّل شهرًا بشهر. */
    public function test_the_settlement_does_not_hit_the_expense_again(): void
    {
        $this->postPayroll();
        $expenseBefore = $this->accountBalance('5210');

        $this->service()->settleMany([$this->taha->id => 208.33], $this->treasury()->id, $this->admin);

        $this->assertSame($expenseBefore, $this->accountBalance('5210'));
        // والمخصّص (٢٢١٠) نقص بقيمة ما صُرف وحده.
        $this->assertSame(166.67, $this->accountBalance('2210'));
    }

    /** وصرف بعضِ المبلغ يُبقي الباقي مخصّصًا. */
    public function test_a_partial_settlement_leaves_the_rest(): void
    {
        $this->postPayroll();

        $this->service()->settleMany([$this->taha->id => 100], $this->treasury()->id, $this->admin);

        $this->assertSame(108.33, $this->service()->balance($this->taha));
    }

    // ────────── ما يُرفض ──────────

    /**
     * **مبلغٌ فوق الرصيد يُبطل الدفعة كلَّها.**
     *
     * دفعةٌ تُصرف نصفَها ثم تتوقّف تترك الخزينة مصروفةً بعضًا والشاشة تقول
     * «فشل» — فيُعاد الإرسال فيُصرف المصروفُ مرّتين.
     */
    public function test_an_amount_above_the_balance_aborts_the_whole_batch(): void
    {
        $this->postPayroll();

        try {
            $this->service()->settleMany([
                $this->taha->id => 208.33,
                $this->hadeel->id => 5000,
            ], $this->treasury()->id, $this->admin);
            $this->fail('كان يجب أن يُرفض.');
        } catch (ValidationException) {
            // المتوقَّع.
        }

        $this->assertSame(208.33, $this->service()->balance($this->taha));
        $this->assertSame(0, FinancialVoucher::where('category', 'end_of_service')->count());
    }

    /** واختيارٌ فارغ يُردّ برسالةٍ مفهومة لا بخطأ. */
    public function test_an_empty_selection_is_refused_with_a_message(): void
    {
        $this->postPayroll();

        $this->post(route('admin.hr.eos.settle'), [
            'treasury_id' => $this->treasury()->id,
            'amounts' => [$this->taha->id => 0],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, FinancialVoucher::where('category', 'end_of_service')->count());
    }

    // ────────── الحركة تتبع سندها ──────────

    /**
     * **تعديل سند التصفية يُصحّح المخصّص.**
     *
     * الحركة نسخةٌ سالبة من مبلغ السند، والسند يُعدَّل بعدها (عكسٌ ثم قيد
     * مُصحّح). وتركُ النسخة قديمةً يجعل دفتر المخصّص يقول رقمًا والدفترَ العامّ
     * رقمًا آخر.
     */
    public function test_editing_the_settlement_voucher_corrects_the_provision(): void
    {
        $this->postPayroll();
        $this->service()->settleMany([$this->taha->id => 200], $this->treasury()->id, $this->admin);

        $voucher = FinancialVoucher::where('category', 'end_of_service')->latest('id')->firstOrFail();
        app(VoucherService::class)->repost($voucher, ['amount' => 150], $this->admin);

        // ٢٠٨٫٣٣ − ١٥٠ = ٥٨٫٣٣
        $this->assertSame(58.33, $this->service()->balance($this->taha));
        $this->assertSame('-150.00', EndOfServiceEntry::where('kind', 'settlement')->firstOrFail()->amount);
    }

    /** **وعكسُ السند يُعيد المخصّص كاملًا** — ماله عاد إلى الخزينة. */
    public function test_reversing_the_settlement_restores_the_provision(): void
    {
        $this->postPayroll();
        $this->service()->settleMany([$this->taha->id => 208.33], $this->treasury()->id, $this->admin);
        $this->assertSame(0.0, $this->service()->balance($this->taha));

        app(VoucherService::class)->reverse(
            FinancialVoucher::where('category', 'end_of_service')->latest('id')->firstOrFail(),
        );

        $this->assertSame(208.33, $this->service()->balance($this->taha));
    }

    // ────────── الشاشة ──────────

    /** الشاشة تعرض الرصيد ومتراكمَ السنة ومصروفَها. */
    public function test_the_screen_separates_the_balance_from_the_year(): void
    {
        $this->postPayroll(7);
        $this->postPayroll(8);
        $this->service()->settleMany([$this->taha->id => 100], $this->treasury()->id, $this->admin);

        $row = $this->service()->yearEndRows(2026)->firstWhere(fn ($r) => $r['profile']->id === $this->taha->id);

        $this->assertSame(416.66, $row['accrued']);   // شهران.
        $this->assertSame(100.0, $row['settled']);
        $this->assertSame(316.66, $row['balance']);
    }

    /** ومن لا مخصّص له لا يظهر — الشاشة للمستحقّين. */
    public function test_employees_without_a_provision_are_not_listed(): void
    {
        $this->postPayroll();
        $idle = $this->employee('لم يدخل كشفًا', 1200); // بعد الترحيل: بلا حركة.

        $rows = $this->service()->yearEndRows(2026);

        $this->assertNotNull($rows->firstWhere(fn ($r) => $r['profile']->id === $this->taha->id));
        $this->assertNull($rows->firstWhere(fn ($r) => $r['profile']->id === $idle->id));
    }

    /**
     * **والرصيد غير المصروف يُرحَّل إلى السنة التالية.**
     *
     * مخصّصٌ لم يُصرف في سنته دَينٌ قائم، لا رقمٌ ينتهي بانتهاء السنة.
     */
    public function test_an_unsettled_balance_carries_into_the_next_year(): void
    {
        $this->postPayroll();

        $row = $this->service()->yearEndRows(2027)->firstWhere(fn ($r) => $r['profile']->id === $this->taha->id);

        $this->assertSame(208.33, $row['balance']);
        $this->assertSame(0.0, $row['accrued']); // ولا يُنسب متراكمًا لسنةٍ لم يتراكم فيها.
    }

    /** والصفحة تُفتح ويظهر فيها الموظف ورصيده. */
    public function test_the_page_renders(): void
    {
        $this->postPayroll();

        $this->get(route('admin.hr.eos.index'))
            ->assertOk()
            ->assertSee('صرف مكافأة نهاية الخدمة')
            ->assertSee('طه')
            ->assertSee('208.33');
    }

    /** والصرف من الشاشة يعمل. */
    public function test_settling_from_the_screen_works(): void
    {
        $this->postPayroll();

        $this->post(route('admin.hr.eos.settle'), [
            'treasury_id' => $this->treasury()->id,
            'note' => 'نقدًا باليد',
            'amounts' => [$this->taha->id => 208.33, $this->hadeel->id => 166.67],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(0.0, $this->service()->balance($this->taha));
    }

    // ────────── الصلاحية ──────────

    /** من يملك الاطّلاع يرى الشاشة ولا يصرف. */
    public function test_a_viewer_cannot_settle(): void
    {
        $this->postPayroll();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('hr.payroll.view');

        $this->actingAs($viewer)->get(route('admin.hr.eos.index'))->assertOk();

        $this->actingAs($viewer)->post(route('admin.hr.eos.settle'), [
            'treasury_id' => $this->treasury()->id,
            'amounts' => [$this->taha->id => 208.33],
        ])->assertForbidden();

        $this->assertSame(208.33, $this->service()->balance($this->taha));
    }
}
