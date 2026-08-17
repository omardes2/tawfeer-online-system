<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Support\PermissionUsage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * مطابقة الصلاحيات المخزَّنة بما يستعمله الكود.
 *
 * الخطر هنا في الاتجاهين: صلاحيةٌ ميتة تُعرَض فيُظنّ أنها تفتح شيئًا، وصلاحيةٌ
 * ناقصة يفحصها الكود فتُغلق شاشةٌ في وجه الجميع بلا رسالة.
 */
class PermissionUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        PermissionUsage::flush();
    }

    /**
     * لا صلاحية يفحصها الكود وهي غائبة عن قاعدة البيانات.
     *
     * هذا ما حدث فعلًا مع «شحنات الاستيراد»: أُضيفت في بذرةٍ وحدها والنشر يشغّل
     * `migrate` لا `seed`، فاختفى البند من القائمة الجانبية حتى عن مدير النظام.
     */
    public function test_no_permission_is_checked_without_existing(): void
    {
        $missing = PermissionUsage::missing(Permission::pluck('name'));

        $this->assertSame([], $missing, 'صلاحيات يفحصها الكود ولا وجود لها: '.implode('، ', $missing));
    }

    /**
     * الصلاحيات المُركَّبة أثناء التنفيذ تُعدّ مستعملة.
     *
     * السندات تفحص `'accounting.'.$res.'.post'` — لا يراها بحثٌ عن نصٍّ كامل.
     * وسمُها «ميتة» ثم إخفاؤها كان سيُعطّل السندات لكل من ليس مدير نظام، بلا أن
     * يظهر ذلك في أي اختبار.
     */
    public function test_dynamically_built_permissions_count_as_used(): void
    {
        foreach ([
            'accounting.expenses.post', 'accounting.receipts.approve',
            'accounting.payments.create', 'accounting.banks.manage',
        ] as $key) {
            $this->assertTrue(PermissionUsage::isUsed($key), "{$key} وُسمت ميتة وهي تُركَّب أثناء التنفيذ.");
        }
    }

    /** وما لا أثر له فعلًا يُكشَف. */
    public function test_a_permission_with_no_trace_is_reported(): void
    {
        Permission::findOrCreate('ghost.module.action', 'web');
        PermissionUsage::flush();

        $this->assertContains('ghost.module.action', PermissionUsage::unused(Permission::pluck('name')));
    }

    /** والصلاحيات الحيّة لا تُوسَم ميتةً. */
    public function test_live_permissions_are_not_reported_as_dead(): void
    {
        $unused = PermissionUsage::unused(Permission::pluck('name'));

        foreach ([
            'reports.ad_budget.view', 'reports.ad_budget.manage', 'catalog.price_list.view',
            'purchasing.shipments.view', 'settings.users.view', 'dashboard.view',
        ] as $key) {
            $this->assertNotContains($key, $unused, "{$key} وُسمت ميتة وهي مستعملة.");
        }
    }

    // ────────── الشاشة ──────────

    /** الميتة مخفيّة افتراضيًّا عن شاشة الأدوار. */
    public function test_the_role_screen_hides_dead_permissions_by_default(): void
    {
        Permission::findOrCreate('ghost.module.action', 'web');
        PermissionUsage::flush();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.roles.edit', Role::where('name', 'accountant')->firstOrFail()))
            ->assertOk()->getContent();

        // موجودة في الصفحة (كي لا تُحذف عند الحفظ) لكنها موسومة ومخفيّة.
        $this->assertStringContainsString('ghost.module.action', $html);
        $this->assertStringContainsString(__('غير مستخدمة'), $html);
    }

    /**
     * صلاحيةٌ ميتة يحملها الدور تبقى ظاهرة — وتنجو من الحفظ.
     *
     * الحفظ يُزامن القائمة المُرسَلة، فحقلٌ مخفيّ لا يُرسَل يعني سحبَ الصلاحية
     * بصمت من دورٍ كان يملكها.
     */
    public function test_a_dead_permission_already_granted_survives_a_save(): void
    {
        $ghost = Permission::findOrCreate('ghost.module.action', 'web');
        $role = Role::where('name', 'accountant')->firstOrFail();
        $role->givePermissionTo($ghost);
        PermissionUsage::flush();

        $kept = $role->fresh()->permissions->pluck('name')->all();

        $this->actingAs($this->admin())
            ->put(route('admin.roles.update', $role), ['name' => $role->name, 'permissions' => $kept])
            ->assertSessionHasNoErrors();

        $this->assertContains('ghost.module.action', $role->fresh()->permissions->pluck('name')->all());
    }

    /** وأمر المطابقة يعمل ويُبلّغ. */
    public function test_the_audit_command_runs(): void
    {
        $this->artisan('permissions:audit')->assertSuccessful();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');

        return $admin;
    }
}
