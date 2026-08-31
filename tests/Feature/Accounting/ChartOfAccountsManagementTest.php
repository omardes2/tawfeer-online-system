<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\AccountService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * إضافة بنودٍ إلى دليل الحسابات — بأصول المحاسبة لا بحرّية مطلقة.
 *
 * الدليل ليس قائمة أسماء: بنيتُه هي ما تُبنى عليه الميزانية وقائمة الدخل وميزان
 * المراجعة. فبندٌ يُضاف في غير موضعه لا يُنتج خطأً ظاهرًا — يُنتج تقريرًا يبدو
 * سليمًا وهو كاذب. وهذه الاختبارات تحرس القواعد الخمس.
 */
class ChartOfAccountsManagementTest extends TestCase
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

    private function service(): AccountService
    {
        return app(AccountService::class);
    }

    private function parent(string $code = '1000'): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    // ────────── القاعدة ١: النوع يُورَث ──────────

    /** **الفرع يرث نوع أبيه** — لا يُختار. */
    public function test_a_child_inherits_the_parent_type(): void
    {
        $parent = $this->parent('1000'); // أصول.

        $child = $this->service()->create(['parent_id' => $parent->id, 'name' => 'أصل جديد']);

        $this->assertSame('asset', $child->type);
        $this->assertSame($parent->id, $child->parent_id);
    }

    /** ولو أُرسل نوعٌ مخالف في الطلب فلا يُقبَل — النموذج لا يحمل الحقل أصلًا. */
    public function test_a_posted_type_is_ignored(): void
    {
        $parent = $this->parent('1000');

        $this->post(route('admin.accounting.accounts.store'), [
            'parent_id' => $parent->id, 'name' => 'محاولة تهريب نوع', 'type' => 'expense',
        ])->assertRedirect();

        $this->assertSame('asset', Account::where('name', 'محاولة تهريب نوع')->firstOrFail()->type);
    }

    // ────────── القاعدة ٢: الأب يفقد الترحيل ──────────

    /**
     * **الأب يصير حساب مراقبة حين يُنجب.**
     *
     * الرصيد يتجمّع من الأوراق إلى الجذر، فأبٌ يُرحَّل عليه مباشرةً ويُجمع مع
     * فروعه يُحتسب مرّتين.
     */
    public function test_the_parent_stops_being_postable(): void
    {
        $parent = $this->parent('1011-0001'); // صندوق قابل للترحيل.
        $this->assertTrue($parent->is_postable);

        $this->service()->create(['parent_id' => $parent->id, 'name' => 'فرع الصندوق']);

        $this->assertFalse($parent->fresh()->is_postable);
    }

    /** وحذفُ آخر فرعٍ يُعيد للأب قابلية الترحيل. */
    public function test_removing_the_last_child_restores_the_parent(): void
    {
        $parent = $this->parent('1011-0001');
        $child = $this->service()->create(['parent_id' => $parent->id, 'name' => 'فرع مؤقّت']);

        $this->service()->delete($child);

        $this->assertTrue($parent->fresh()->is_postable);
    }

    // ────────── القاعدة ٣: الرمز تحت أبيه ──────────

    /** الرمز المُقترَح يتبع رمز الأب. */
    public function test_the_suggested_code_sits_under_the_parent(): void
    {
        $parent = $this->parent('1000');

        $child = $this->service()->create(['parent_id' => $parent->id, 'name' => 'فرع']);

        $this->assertStringStartsWith('1000-', $child->code);
    }

    /** **ورمزٌ خارج تسلسل الأب يُرفض** — يُقرأ تحت أبٍ آخر في كل تقرير. */
    public function test_a_code_outside_the_parent_is_refused(): void
    {
        $parent = $this->parent('1000');

        $this->expectException(ValidationException::class);
        $this->service()->create(['parent_id' => $parent->id, 'name' => 'فرع', 'code' => '9999-0001']);
    }

    // ────────── القاعدة ٤: تفرّد الرمز ──────────

    /** الرمز المستعمل يُرفض برسالةٍ مفهومة لا بخطأ قاعدة بيانات. */
    public function test_a_duplicate_code_is_refused(): void
    {
        $parent = $this->parent('1000');

        $this->expectException(ValidationException::class);
        $this->service()->create(['parent_id' => $parent->id, 'name' => 'فرع', 'code' => '1010']);
    }

    /** والمحذوف ناعمًا يحتلّ رمزه — قيد التفرّد يشمله. */
    public function test_a_trashed_code_is_not_reused(): void
    {
        $parent = $this->parent('1000');
        $first = $this->service()->create(['parent_id' => $parent->id, 'name' => 'أول']);
        $code = $first->code;
        $first->delete();

        $second = $this->service()->create(['parent_id' => $parent->id, 'name' => 'ثانٍ']);

        $this->assertNotSame($code, $second->code);
    }

    // ────────── القاعدة ٥: ما تحرّك لا يُحذف ──────────

    /** **حسابٌ عليه قيدٌ مُرحَّل لا يُحذف** — تُيتَّم قيوده. */
    public function test_an_account_with_entries_cannot_be_deleted(): void
    {
        $parent = $this->parent('1000');
        $account = $this->service()->create(['parent_id' => $parent->id, 'name' => 'حساب متحرّك']);

        app(AccountingService::class)->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => 'قيد اختبار',
            'source' => 'manual',
        ], [
            ['account_code' => $account->code, 'debit' => 100, 'credit' => 0],
            ['account_code' => '3010', 'debit' => 0, 'credit' => 100],
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->delete($account->fresh());
    }

    /** وحسابٌ له فروعٌ لا يُحذف. */
    public function test_an_account_with_children_cannot_be_deleted(): void
    {
        $parent = $this->parent('1000');
        $mid = $this->service()->create(['parent_id' => $parent->id, 'name' => 'وسيط']);
        $this->service()->create(['parent_id' => $mid->id, 'name' => 'ورقة']);

        $this->expectException(ValidationException::class);
        $this->service()->delete($mid->fresh());
    }

    // ────────── التعديل ──────────

    /** الاسم والتفعيل يُعدَّلان. */
    public function test_the_name_and_activation_can_be_edited(): void
    {
        $account = $this->service()->create(['parent_id' => $this->parent('1000')->id, 'name' => 'اسم قديم']);

        $this->service()->update($account, ['name' => 'اسم جديد', 'is_active' => false]);

        $this->assertSame('اسم جديد', $account->fresh()->name);
        $this->assertFalse($account->fresh()->is_active);
    }

    /** **والرمز والنوع لا يُعدَّلان ولو أُرسلا.** */
    public function test_the_code_and_type_are_immutable(): void
    {
        $account = $this->service()->create(['parent_id' => $this->parent('1000')->id, 'name' => 'حساب']);
        $code = $account->code;

        $this->put(route('admin.accounting.accounts.update', $account), [
            'name' => 'حساب', 'code' => '7777', 'type' => 'expense', 'is_active' => 1,
        ])->assertRedirect();

        $fresh = $account->fresh();
        $this->assertSame($code, $fresh->code);
        $this->assertSame('asset', $fresh->type);
    }

    /** ولا يُعطَّل أبٌ له فروعٌ نشطة — تبقى معلّقةً تحت معطَّل. */
    public function test_a_parent_with_active_children_cannot_be_deactivated(): void
    {
        $mid = $this->service()->create(['parent_id' => $this->parent('1000')->id, 'name' => 'وسيط']);
        $this->service()->create(['parent_id' => $mid->id, 'name' => 'ورقة']);

        $this->expectException(ValidationException::class);
        $this->service()->update($mid->fresh(), ['is_active' => false]);
    }

    // ────────── الصلاحية والشاشة ──────────

    /** الشاشة تعرض نموذج الإضافة لمن يملك الإدارة. */
    public function test_the_form_is_shown_to_a_manager(): void
    {
        $this->get(route('admin.accounting.accounts.index'))
            ->assertOk()
            ->assertSee('إضافة بند إلى الدليل');
    }

    /** **ولا تُقبل الإضافة ممّن لا يملكها.** */
    public function test_a_user_without_the_permission_is_refused(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('accounting.accounts.view');

        $this->actingAs($viewer)
            ->post(route('admin.accounting.accounts.store'), [
                'parent_id' => $this->parent('1000')->id, 'name' => 'محاولة',
            ])->assertForbidden();

        $this->assertDatabaseMissing('accounts', ['name' => 'محاولة']);
    }

    /** ولا يظهر له النموذج أصلًا. */
    public function test_the_form_is_hidden_from_a_viewer(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('accounting.accounts.view');

        $this->actingAs($viewer)
            ->get(route('admin.accounting.accounts.index'))
            ->assertOk()
            ->assertDontSee('إضافة بند إلى الدليل');
    }
}
