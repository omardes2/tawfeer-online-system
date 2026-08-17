<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Support\PermissionLabel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * وضوح شاشة الأدوار والصلاحيات.
 *
 * الخطر هنا ليس عطلًا بل غموضًا: صلاحيةٌ لا يفهمها موزّعُ الأدوار تُمنح «احتياطًا»
 * أو تُمنع بلا داعٍ. وأسوأ حالاته صلاحية تظهر بفعلٍ مجرّد («عرض») بلا ذكر ما
 * يُعرَض — عشرون صلاحية كانت كذلك.
 */
class PermissionLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** لا صلاحية تُعرَض باسم فعلٍ مجرّد بلا مورد. */
    public function test_every_permission_names_what_it_acts_on(): void
    {
        $bare = Permission::pluck('name')
            ->reject(fn (string $key) => str_contains(PermissionLabel::for($key), '—'))
            ->all();

        $this->assertSame([], $bare, 'صلاحيات تظهر بفعلٍ مجرّد: '.implode('، ', $bare));
    }

    /** ولكلٍّ منها شرحٌ لا يكرّر اسمها. */
    public function test_every_permission_has_a_description(): void
    {
        foreach (Permission::pluck('name') as $key) {
            $hint = PermissionLabel::describe($key);

            $this->assertNotSame('', trim($hint), "الصلاحية {$key} بلا شرح.");
            $this->assertNotSame(PermissionLabel::for($key), $hint, "شرح {$key} يكرّر اسمها بلا فائدة.");
        }
    }

    /**
     * الأفعال المتقاربة تُشرَح بما يفرّقها.
     *
     * «اعتماد» و«ترحيل» و«عكس» تبدو مترادفة لمن لا يعرف الدفاتر.
     */
    public function test_the_confusable_verbs_are_told_apart(): void
    {
        $this->assertStringContainsString('الموافقة', PermissionLabel::describe('accounting.expenses.approve'));
        $this->assertStringContainsString('لا تُحذف', PermissionLabel::describe('accounting.journal.post'));
        $this->assertStringContainsString('عاكس', PermissionLabel::describe('accounting.journal.reverse'));
    }

    /** وما يُخرج مالًا أو لا يُتراجَع عنه أو يكشف التكلفة موسومٌ حسّاسًا. */
    public function test_money_and_irreversible_actions_are_flagged(): void
    {
        foreach ([
            'accounting.journal.post', 'accounting.journal.reverse', 'commissions.payout',
            'pricing.view_cost', 'settings.roles.manage', 'purchasing.shipments.close',
        ] as $key) {
            $this->assertTrue(PermissionLabel::isSensitive($key), "{$key} غير موسومة حسّاسة.");
        }

        // والاطّلاع المجرّد ليس حسّاسًا، وإلّا فقد الوسم معناه.
        $this->assertFalse(PermissionLabel::isSensitive('dashboard.view'));
        $this->assertFalse(PermissionLabel::isSensitive('catalog.products.view'));
    }

    /** والصفحة تعرض الشرح والوسم فعلًا لا التسمية وحدها. */
    public function test_the_role_screen_shows_the_explanations(): void
    {
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.roles.edit', Role::where('name', 'accountant')->firstOrFail()))
            ->assertOk()->getContent();

        $this->assertStringContainsString(PermissionLabel::describe('accounting.journal.post'), $html);
        $this->assertStringContainsString(__('حسّاسة'), $html);
        // ودليل الأفعال حاضر: يُقرأ مرّة ويُغني عن التخمين.
        $this->assertStringContainsString(__('ماذا تعني هذه الأفعال؟ (اقرأها مرّة واحدة)'), $html);
    }

    /** والصلاحيات الجديدة مشروحة لا مجرّد مفاتيح. */
    public function test_the_newest_permissions_are_explained(): void
    {
        $this->assertStringContainsString('الميزانية اليومية', PermissionLabel::for('reports.ad_budget.view'));
        $this->assertStringContainsString('التكلفة', PermissionLabel::describe('reports.ad_budget.view'));
        $this->assertStringContainsString('الصرف الإعلاني', PermissionLabel::describe('reports.ad_budget.manage'));
        $this->assertStringContainsString('قائمة الأسعار', PermissionLabel::for('catalog.price_list.view'));
    }
}
