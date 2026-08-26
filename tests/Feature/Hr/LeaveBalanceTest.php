<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Services\LeaveService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رصيد الإجازة السنوية.
 *
 * أيامٌ لا مال: الإجازة المدفوعة لا تُنشئ قيدًا لأن الموظف يقبض راتبه كاملًا
 * سواءٌ عمل أم أخذ إجازته. فما يُختبر هنا هو **الاستحقاق والخصم منه**.
 */
class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function employee(
        string $hire = '2025-01-01',
        float $days = 14,
        ?string $end = null,
        string $type = 'full_time',
    ): EmployeeProfile {
        return EmployeeProfile::create([
            'user_id' => User::factory()->create()->id,
            'hire_date' => $hire,
            'end_date' => $end,
            'status' => $end ? 'ended' : 'active',
            'employment_type' => $type,
            'annual_leave_days' => $days,
            'created_by' => $this->admin->id,
        ]);
    }

    private function service(): LeaveService
    {
        return app(LeaveService::class);
    }

    /** سنةٌ خُدمت كاملةً تستحقّ الرصيد كاملًا. */
    public function test_a_full_year_earns_the_full_entitlement(): void
    {
        $profile = $this->employee(hire: '2025-01-01', days: 14);

        $this->assertEqualsWithDelta(14.0, $this->service()->entitlementFor($profile, 2026), 0.01);
    }

    /**
     * **وسنة التعيين بالتناسب.**
     *
     * من عُيّن في تموز لا يستحقّ إجازة سنةٍ كاملة في كانون الأول — وإلا ظهر
     * رصيدٌ لم يُكتسَب.
     */
    public function test_the_hiring_year_is_prorated(): void
    {
        $profile = $this->employee(hire: '2026-07-01', days: 12);

        // من ١ تموز إلى ٣١ كانون الأول = ١٨٤ يومًا من ٣٦٥.
        $this->assertEqualsWithDelta(6.05, $this->service()->entitlementFor($profile, 2026), 0.1);
    }

    /** وسنةٌ سابقة للتعيين لا تستحقّ شيئًا. */
    public function test_a_year_before_hiring_earns_nothing(): void
    {
        $profile = $this->employee(hire: '2026-01-01');

        $this->assertEqualsWithDelta(0.0, $this->service()->entitlementFor($profile, 2025), 0.01);
    }

    /** وسنة انتهاء الخدمة بالتناسب أيضًا. */
    public function test_the_final_year_is_prorated(): void
    {
        $profile = $this->employee(hire: '2024-01-01', days: 12, end: '2026-06-30');

        // من ١ كانون الثاني إلى ٣٠ حزيران = ١٨١ يومًا من ٣٦٥.
        $this->assertEqualsWithDelta(5.95, $this->service()->entitlementFor($profile, 2026), 0.1);
    }

    /** والمأخوذ يُنقص الرصيد. */
    public function test_taken_annual_days_reduce_the_balance(): void
    {
        $profile = $this->employee(days: 14);

        $profile->leaves()->create([
            'kind' => 'annual', 'from_date' => '2026-03-01', 'to_date' => '2026-03-05', 'days' => 5,
        ]);

        $balance = $this->service()->balance($profile, 2026);

        $this->assertEqualsWithDelta(5.0, $balance['taken'], 0.01);
        $this->assertEqualsWithDelta(9.0, $balance['remaining'], 0.01);
    }

    /** والإجازة بلا راتب لا تمسّ الرصيد — تُخصَم من الراتب لا من الأيام. */
    public function test_unpaid_leave_does_not_reduce_the_annual_balance(): void
    {
        $profile = $this->employee(days: 14);

        $profile->leaves()->create([
            'kind' => 'unpaid', 'from_date' => '2026-03-01', 'to_date' => '2026-03-05', 'days' => 5,
        ]);

        $balance = $this->service()->balance($profile, 2026);

        $this->assertEqualsWithDelta(14.0, $balance['remaining'], 0.01);
        $this->assertEqualsWithDelta(5.0, $balance['unpaid'], 0.01);
    }

    /** والمرضية تُسجَّل للسجلّ ولا تمسّ رصيدًا ولا راتبًا. */
    public function test_sick_leave_touches_neither(): void
    {
        $profile = $this->employee(days: 14);

        $profile->leaves()->create([
            'kind' => 'sick', 'from_date' => '2026-03-01', 'to_date' => '2026-03-02', 'days' => 2,
        ]);

        $balance = $this->service()->balance($profile, 2026);

        $this->assertEqualsWithDelta(14.0, $balance['remaining'], 0.01);
        $this->assertEqualsWithDelta(2.0, $balance['sick'], 0.01);
    }

    /** وسنةٌ أخرى لا تتسرّب إلى رصيدها. */
    public function test_another_year_does_not_leak_in(): void
    {
        $profile = $this->employee(days: 14);

        $profile->leaves()->create([
            'kind' => 'annual', 'from_date' => '2025-03-01', 'to_date' => '2025-03-10', 'days' => 10,
        ]);

        $this->assertEqualsWithDelta(14.0, $this->service()->balance($profile, 2026)['remaining'], 0.01);
    }

    /**
     * **والرصيد بالسالب مسموحٌ ومُنبَّهٌ عليه.**
     *
     * الشركة قد تمنح إجازةً على حساب السنة القادمة. ومنعُ التسجيل يدفع إلى
     * ألّا تُسجَّل الإجازة أصلًا — فيُفقَد الأثر كلّه.
     */
    public function test_the_balance_may_go_negative(): void
    {
        $profile = $this->employee(days: 14);

        $profile->leaves()->create([
            'kind' => 'annual', 'from_date' => '2026-03-01', 'to_date' => '2026-03-20', 'days' => 20,
        ]);

        $this->assertEqualsWithDelta(-6.0, $this->service()->balance($profile, 2026)['remaining'], 0.01);
    }

    // ────────── نوع التعاقد ──────────

    /**
     * **العقد والدوام الجزئيّ بلا رصيد إجازة.**
     *
     * أجرٌ مقابل عمل: لا إجازةَ سنوية تتراكم. وتركُ الاستحقاق لهما يُظهر رصيدًا
     * لا يقوم على اتفاق.
     */
    public function test_contract_and_part_time_earn_no_annual_leave(): void
    {
        foreach (['contract', 'part_time'] as $type) {
            $profile = $this->employee(days: 14, type: $type);

            $this->assertEqualsWithDelta(0.0, $this->service()->entitlementFor($profile, 2026), 0.01, $type);
            $this->assertEqualsWithDelta(0.0, $this->service()->balance($profile, 2026)['entitlement'], 0.01, $type);
        }
    }

    /**
     * وتسجيل الإجازة يبقى ممكنًا لهما — للسجلّ.
     *
     * فالمنعُ يدفع إلى ألّا يُسجَّل الغياب أصلًا، وغيرُ المدفوعة تُخصَم من
     * راتبها كالجميع.
     */
    public function test_they_can_still_have_leaves_recorded(): void
    {
        $profile = $this->employee(type: 'contract');

        $profile->leaves()->create([
            'kind' => 'unpaid', 'from_date' => '2026-03-01', 'to_date' => '2026-03-03', 'days' => 3,
        ]);

        $balance = $this->service()->balance($profile, 2026);

        $this->assertEqualsWithDelta(3.0, $balance['unpaid'], 0.01);
        $this->assertEqualsWithDelta(0.0, $balance['entitlement'], 0.01);
    }

    /** والشاشة تقول السبب بدل أن تعرض صفرًا يُقرأ «استُهلك رصيده». */
    public function test_the_screen_explains_why_there_is_no_balance(): void
    {
        $profile = $this->employee(type: 'contract');

        $this->actingAs($this->admin)->get(route('admin.hr.employees.show', $profile))
            ->assertOk()
            ->assertSee('لا رصيد إجازةٍ سنوية ولا مكافأة نهاية خدمة', false);
    }

    /** وتسجيل الإجازة من الشاشة يعمل. */
    public function test_a_leave_can_be_recorded_from_the_screen(): void
    {
        $profile = $this->employee();

        $this->actingAs($this->admin)->post(route('admin.hr.employees.leaves.store', $profile), [
            'kind' => 'annual',
            'from_date' => '2026-04-01',
            'to_date' => '2026-04-03',
            'days' => 3,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(3.0, (float) $profile->leaves()->sum('days'), 0.01);
    }
}
