<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Accounting\Models\ExpenseCategory;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\ExpenseCategoryService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Reporting\Services\ProfitLossService;
use App\Modules\Reporting\Support\DateRange;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الميزانية لا تعدّ المصروف مرّتين.
 *
 * ## المشكلة
 *
 * قائمة الأرباح والخسائر تقرأ الإعلانات من جدول الصرف الإعلاني، والعمولات من
 * دفتر العمولات — **استحقاقًا لا صرفًا**. ثم تجمع فوقهما كلَّ سندات الصرف
 * بتصنيفاتها. فسندُ مصروفٍ بتصنيف «إعلانات» كان يُجمع مرّتين: مرّةً من جدوله
 * ومرّةً من سنده، بلا ما يكشف الازدواج على الشاشة.
 *
 * ## الحلّ
 *
 * التصنيف الموسوم بـ`auto_source` تُفرَز سنداتُه إلى قائمةٍ تُعرَض للعِلم ولا
 * تدخل الإجمالي. ولا يُمنع تسجيلُها: الدفعة النقدية واقعةٌ حقيقية، والخطأ في
 * **عدّها مرّتين** لا في تسجيلها.
 */
class ProfitLossNoDoubleCountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function category(string $name, ?string $autoSource = null): ExpenseCategory
    {
        return app(ExpenseCategoryService::class)->create([
            'name' => $name,
            'auto_source' => $autoSource,
        ]);
    }

    /**
     * سند صرفٍ مُرحَّل على تصنيف.
     *
     * `counter_account_id` من حساب التصنيف: السند لا يُرحَّل بلا طرفٍ مقابل،
     * وحسابُ التصنيف هو الطرف الذي يُفتح له تلقائيًّا عند إنشائه.
     */
    private function spend(ExpenseCategory $category, float $amount): void
    {
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create('expense', [
            'treasury_id' => Treasury::active()->firstOrFail()->id,
            'amount' => $amount,
            'expense_category_id' => $category->id,
            'counter_account_id' => $category->account_id,
            'description' => 'صرف '.$category->name,
            'voucher_date' => now()->toDateString(),
        ]);
        $vouchers->post($vouchers->approve($voucher));
    }

    /** @return array<string, mixed> */
    private function expenses(): array
    {
        return app(ProfitLossService::class)
            ->report(DateRange::resolve('this_month'))['expenses'];
    }

    // ────────── جوهر العطب ──────────

    /** **سندُ تصنيفٍ محتسَبٍ آليًّا لا يدخل الإجمالي.** */
    public function test_an_auto_counted_voucher_is_excluded_from_the_total(): void
    {
        $before = $this->expenses()['total'];

        $this->spend($this->category('إعلانات ميتا', 'ads'), 5000);

        $this->assertEqualsWithDelta($before, $this->expenses()['total'], 0.01);
    }

    /** ويظهر في قائمةٍ منفصلة — لا يُحذف من الشاشة. */
    public function test_it_is_still_shown_separately(): void
    {
        $this->spend($this->category('إعلانات ميتا', 'ads'), 5000);

        $expenses = $this->expenses();

        $this->assertEqualsWithDelta(5000.0, $expenses['auto_counted_total'], 0.01);
        $this->assertSame('إعلانات ميتا', $expenses['auto_counted']->first()['name']);
        $this->assertNotNull($expenses['auto_counted']->first()['auto_source']);
    }

    /** ولا يظهر في التصنيفات المجموعة — وإلا عُدّ مرّتين. */
    public function test_it_is_not_in_the_counted_categories(): void
    {
        $this->spend($this->category('إعلانات ميتا', 'ads'), 5000);

        $names = $this->expenses()['categories']->pluck('name');

        $this->assertNotContains('إعلانات ميتا', $names);
    }

    // ────────── التصنيف العادي لم يتغيّر ──────────

    /** **والتصنيف العادي يُجمع كما كان** — الوسم استثناءٌ لا قاعدة. */
    public function test_an_ordinary_category_is_still_counted(): void
    {
        $before = $this->expenses()['total'];

        $this->spend($this->category('عمال تنزيل'), 900);

        $expenses = $this->expenses();

        $this->assertEqualsWithDelta($before + 900, $expenses['total'], 0.01);
        $this->assertContains('عمال تنزيل', $expenses['categories']->pluck('name'));
    }

    /** والاثنان معًا: يُجمع العادي وحده. */
    public function test_only_the_ordinary_one_is_summed(): void
    {
        $before = $this->expenses()['total'];

        $this->spend($this->category('عمال تنزيل'), 900);
        $this->spend($this->category('عمولات مسوّقين', 'commissions'), 4000);

        $expenses = $this->expenses();

        $this->assertEqualsWithDelta($before + 900, $expenses['total'], 0.01);
        $this->assertEqualsWithDelta(4000.0, $expenses['auto_counted_total'], 0.01);
    }

    /** ورفعُ الوسم يُعيد السند إلى الإجمالي. */
    public function test_clearing_the_flag_brings_the_voucher_back(): void
    {
        $category = $this->category('إعلانات ميتا', 'ads');
        $this->spend($category, 5000);

        $before = $this->expenses()['total'];

        app(ExpenseCategoryService::class)->update($category->fresh(), [
            'name' => 'إعلانات ميتا', 'auto_source' => null,
        ]);

        $this->assertEqualsWithDelta($before + 5000, $this->expenses()['total'], 0.01);
    }

    // ────────── الشاشات ──────────

    /** والتقرير يقول على الشاشة إن هذه السندات لم تُجمَع. */
    public function test_the_report_says_they_were_not_summed(): void
    {
        $this->spend($this->category('إعلانات ميتا', 'ads'), 5000);

        $this->get(route('admin.reports.profit_loss'))
            ->assertOk()
            ->assertSee('لم تُجمَع أعلاه');
    }

    /** ومصدرٌ غير معروف يُرفض — وإلا خرج المبلغ من الإجمالي بلا أن يُحتسب في مكان. */
    public function test_an_unknown_auto_source_is_refused(): void
    {
        $this->post(route('admin.accounting.expense_categories.store'), [
            'name' => 'تصنيف بمصدر مجهول',
            'auto_source' => 'something_else',
        ])->assertSessionHasErrors('auto_source');
    }
}
