<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ExpenseCategory;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\ExpenseCategoryService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تصنيفات المصروفات.
 *
 * الوعد الذي تحرسه هذه الاختبارات بسيط: يكتب المستخدم «عمال تنزيل» فيصير له
 * حسابٌ في دليل المحاسبة يُرحَّل عليه — بلا أن يعرف رمزًا ولا يفتح الدليل.
 * وما يليه حراسةُ ألّا يفسد الدليلُ بذلك: رمزٌ لا يتكرّر، واسمٌ لا يفترق بين
 * القائمة والدليل، وحسابٌ تحرّك لا يُترك بلا اسم.
 */
class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategoryService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        $this->service = app(ExpenseCategoryService::class);
    }

    private function parentId(): int
    {
        return Account::where('code', '5100')->value('id');
    }

    // ────────── الوعد ──────────

    /** التصنيف يفتح حسابه تحت «مصاريف تشغيلية» باسمه. */
    public function test_a_new_category_opens_its_account(): void
    {
        $category = $this->service->create(['name' => 'عمال تنزيل']);
        $account = $category->account;

        $this->assertNotNull($account);
        $this->assertSame('عمال تنزيل', $account->name);
        $this->assertSame($this->parentId(), $account->parent_id);
        $this->assertSame('expense', $account->type);
        $this->assertTrue($account->is_postable);
        $this->assertSame('5100-0001', $account->code);
    }

    /** والأب نفسه لا يُرحَّل عليه — الترحيل على التصنيفات وحدها. */
    public function test_the_parent_account_is_not_postable(): void
    {
        $this->assertFalse((bool) Account::where('code', '5100')->value('is_postable'));
    }

    /**
     * ولا يقع تحت «المصروفات 5000» مباشرةً.
     *
     * تحته تعيش حسابات النظام — فروق تقدير الاستيراد وفروق الصرف — وهي نتائج
     * تقديرٍ لا مصروفٌ أُنفق؛ خلطُها بتصنيفات المستخدم يجعل تقرير المصاريف
     * التشغيلية يبتلعها.
     */
    public function test_operating_expenses_are_separated_from_system_accounts(): void
    {
        $category = $this->service->create(['name' => 'قرطاسية']);

        $this->assertNotSame(
            Account::where('code', '5000')->value('id'),
            $category->account->parent_id,
        );
        foreach (['5050', '5060'] as $systemCode) {
            $this->assertNotSame($this->parentId(), Account::where('code', $systemCode)->value('parent_id'));
        }
    }

    // ────────── حراسة الدليل ──────────

    /** التسمية تُزامن على الحساب — وإلا قرأ المحاسب في الميزان اسمًا لا يجده في اللوحة. */
    public function test_renaming_a_category_renames_its_account(): void
    {
        $category = $this->service->create(['name' => 'عمال تنزيل']);

        $this->service->update($category, ['name' => 'عمال التحميل والتنزيل']);

        $this->assertSame('عمال التحميل والتنزيل', $category->fresh()->account->name);
    }

    /**
     * الرمز لا يتكرّر بعد حذف.
     *
     * لو اشتُقّ التسلسل من عدد الأبناء لأعاد بعد الحذف رمزًا مستعملًا — ورمزُ
     * الحساب فريدٌ في الدليل، فيسقط الإنشاء بخطأ لا يفهمه المستخدم.
     */
    public function test_codes_do_not_repeat_after_a_deletion(): void
    {
        $first = $this->service->create(['name' => 'أول']);
        $second = $this->service->create(['name' => 'ثانٍ']);
        $this->service->delete($second);

        $third = $this->service->create(['name' => 'ثالث']);

        $this->assertSame('5100-0001', $first->account->code);
        $this->assertSame('5100-0003', $third->account->code);
    }

    /** تصنيفٌ تحرّك حسابه لا يُحذف — يبقى ليحمل اسم الرقم في التقارير. */
    public function test_a_category_with_activity_cannot_be_deleted(): void
    {
        $category = $this->service->create(['name' => 'عمال تنزيل']);
        $this->postExpense($category, 250);

        $this->expectException(ValidationException::class);
        $this->service->delete($category->fresh());
    }

    /** وتصنيف النظام لا يُحذف — حسابه مسارٌ يُرحّل عليه النظام آليًا. */
    public function test_a_system_category_cannot_be_deleted(): void
    {
        $system = ExpenseCategory::where('is_system', true)->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->service->delete($system);
    }

    /** والحساب لا يُحذف مع التصنيف — يُعطَّل فقط. */
    public function test_deleting_a_category_only_deactivates_its_account(): void
    {
        $category = $this->service->create(['name' => 'مؤقّت']);
        $accountId = $category->account_id;

        $this->service->delete($category);

        $this->assertSoftDeleted('expense_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'is_active' => false]);
    }

    // ────────── السند ──────────

    /** سند المصروف على تصنيف يُرحَّل على حسابه. */
    public function test_an_expense_voucher_posts_to_the_category_account(): void
    {
        $category = $this->service->create(['name' => 'عمال تنزيل']);

        $voucher = $this->postExpense($category, 300);

        $this->assertSame($category->account_id, $voucher->counter_account_id);
        $this->assertEqualsWithDelta(
            300.0,
            (float) $voucher->journalEntry->lines()->where('account_id', $category->account_id)->sum('debit'),
            0.01,
        );
    }

    /** والشاشة تشتقّ الحساب من التصنيف — المستخدم لا يُرسل رمز حساب أصلًا. */
    public function test_the_form_derives_the_account_from_the_category(): void
    {
        $category = $this->service->create(['name' => 'عمال تنزيل']);

        $this->post(route('admin.accounting.vouchers.store', 'expense'), [
            'voucher_date' => now()->toDateString(),
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->value('id'),
            'expense_category_id' => $category->id,
            'amount' => 120,
        ])->assertRedirect();

        $this->assertDatabaseHas('financial_vouchers', [
            'kind' => 'expense',
            'expense_category_id' => $category->id,
            'counter_account_id' => $category->account_id,
        ]);
    }

    // ────────── الإضافة السريعة ──────────

    /** النافذة داخل السند تُنشئ التصنيف وتُعيده — بلا مغادرة الصفحة. */
    public function test_the_quick_add_endpoint_returns_the_new_category(): void
    {
        $response = $this->postJson(route('admin.accounting.expense_categories.store'), ['name' => 'عمال تنزيل']);

        $response->assertCreated()
            ->assertJson(['name' => 'عمال تنزيل', 'account_code' => '5100-0001']);
    }

    /** واسمٌ مكرّر يُرفض: تصنيفان بالاسم نفسه رقمان لا يجتمعان في تقرير. */
    public function test_a_duplicate_name_is_refused(): void
    {
        $this->service->create(['name' => 'عمال تنزيل']);

        $this->postJson(route('admin.accounting.expense_categories.store'), ['name' => 'عمال تنزيل'])
            ->assertStatus(422);
    }

    /** ومن لا يملك الصلاحية لا يفتح حسابًا في دليل المحاسبة. */
    public function test_creating_requires_the_permission(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('warehouse');

        $this->actingAs($user)
            ->postJson(route('admin.accounting.expense_categories.store'), ['name' => 'عمال تنزيل'])
            ->assertForbidden();
    }

    /** @return FinancialVoucher */
    private function postExpense(ExpenseCategory $category, float $amount)
    {
        $vouchers = app(VoucherService::class);

        $voucher = $vouchers->create('expense', [
            'treasury_id' => Treasury::where('code', 'CB-MAIN')->value('id'),
            'counter_account_id' => $category->account_id,
            'expense_category_id' => $category->id,
            'amount' => $amount,
        ]);
        $vouchers->approve($voucher);

        return $vouchers->post($voucher->fresh())->load('journalEntry');
    }
}
