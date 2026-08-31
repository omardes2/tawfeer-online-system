<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\EmployeeSalary;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إعادة توليد مسيّرٍ بعد حذف مسودّته.
 *
 * ## العطب
 *
 * الفهرس `payroll_runs_period_unique` على (السنة، الشهر) **لا يعرف الحذف
 * الناعم**: مسيّرٌ حُذف يبقى محتلًّا شهره في قاعدة البيانات. وكان البحث عن مسيّر
 * الشهر يُخفي المحذوف فلا يجده، فيمضي إلى الإدراج ويصطدم بالفهرس:
 *
 * ```
 * SQLSTATE[23000]: Duplicate entry '2026-8' for key 'payroll_runs_period_unique'
 * ```
 *
 * أي خطأ ٥٠٠ في الشاشة بلا رسالةٍ تدلّ عليه — والقائمة تقول «لا مسيّرات بعد»
 * لأن المحذوف مُخفًى عنها، فيبدو الشهر فارغًا وهو مشغول.
 *
 * ## الحلّ
 *
 * البحث يشمل المحذوف، والمحذوف **يُستعاد لا يُتجاوَز**: الحذف لا يُسمح به إلّا
 * للمسودّة، فاستعادتُها لإعادة التوليد آمنة — وبنودُها تُمحى وتُبنى من جديد.
 */
class PayrollRegenerateAfterDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);

        $worker = User::factory()->create(['name' => 'موظف']);
        $profile = EmployeeProfile::create([
            'user_id' => $worker->id,
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
            'basic_salary' => 3000,
            'allowances' => 200,
            'created_by' => $this->admin->id,
        ]);
    }

    private function generate(): PayrollRun
    {
        return app(PayrollService::class)->generate(2026, 8, $this->admin);
    }

    // ────────── جوهر العطب ──────────

    /** **إعادة التوليد بعد الحذف تنجح** — كانت تصطدم بالفهرس الفريد. */
    public function test_regenerating_after_a_delete_succeeds(): void
    {
        $first = $this->generate();
        $first->lines()->delete();
        $first->delete();

        $second = $this->generate();

        $this->assertSame(2026, $second->period_year);
        $this->assertSame(8, $second->period_month);
        $this->assertFalse($second->trashed());
    }

    /** ومن الشاشة كذلك — لا خطأ ٥٠٠. */
    public function test_the_button_works_after_a_delete(): void
    {
        $run = $this->generate();

        $this->delete(route('admin.hr.payroll.destroy', $run))->assertRedirect();

        $this->post(route('admin.hr.payroll.generate'), [
            'period_year' => 2026, 'period_month' => 8,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    /** والمسيّر المُستعاد هو نفسه برقمه الأول — لا تُحرق أرقامٌ بلا مستند. */
    public function test_the_restored_run_keeps_its_number(): void
    {
        $first = $this->generate();
        $number = $first->number;
        $first->delete();

        $this->assertSame($number, $this->generate()->number);
        $this->assertSame(1, PayrollRun::withTrashed()->count());
    }

    /** وبنودُه تُبنى من جديد لا تُضاعَف. */
    public function test_the_lines_are_rebuilt_not_duplicated(): void
    {
        $first = $this->generate();
        $count = $first->lines()->count();
        $first->delete();

        $this->assertSame($count, $this->generate()->lines()->count());
    }

    // ────────── ما لا يُستعاد ──────────

    /**
     * **والمُرحَّل يُرفض برسالةٍ لا بخطأ قاعدة بيانات.**
     *
     * مستندٌ محاسبيّ لا يُعاد بناؤه — يُصحَّح بالعكس.
     */
    public function test_a_posted_run_is_refused_with_a_message(): void
    {
        $run = $this->generate();
        $run->forceFill(['status' => 'posted'])->save();

        $this->post(route('admin.hr.payroll.generate'), [
            'period_year' => 2026, 'period_month' => 8,
        ])->assertRedirect()->assertSessionHas('error');
    }

    /** وإعادة التوليد على مسودّةٍ قائمة تُعيد بناءها كما كانت. */
    public function test_regenerating_an_existing_draft_still_works(): void
    {
        $first = $this->generate();

        $second = $this->generate();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PayrollRun::withTrashed()->count());
    }
}
