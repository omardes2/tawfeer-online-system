<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Support\AdminNavigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حصر الميزات الجديدة بمدير النظام — مرحلة التجربة.
 *
 * الشاشات المبنيّة حديثًا لم تُجرَّب على بيانات حقيقية، وفتحُها للفريق قبل
 * التجربة يُنتج قراراتٍ مبنيّة على شاشةٍ لم تُراجَع: موظفٌ يتّصل بقائمةٍ خاطئة،
 * أو تاجرٌ يشتري بسعرٍ أُدخل تجريبًا.
 *
 * ويُفحص **الرابط والقائمة معًا**: بندٌ ظاهرٌ في القائمة يوصل إلى 403 يبدو
 * عطلًا، وشاشةٌ محميّة برابطٍ مكشوف تُفتح بالعنوان مباشرةً.
 */
class TrialAccessLockdownTest extends TestCase
{
    use RefreshDatabase;

    /** كل ما بُني حديثًا. */
    private const TRIAL_ROUTES = [
        'admin.sales.abandoned_checkouts.index',
        'admin.price_lists.index',
        'admin.reports.product_decision',
        'admin.reports.sales.by_location',
        'admin.reports.ad_budget',
    ];

    /** الأدوار التي يجب أن تُمنع جميعًا. */
    private const OTHER_ROLES = ['manager', 'sales_supervisor', 'sales', 'affiliate', 'warehouse', 'accountant', 'finance'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    /** مدير النظام يفتحها كلّها. */
    public function test_the_system_admin_reaches_every_new_screen(): void
    {
        foreach (self::TRIAL_ROUTES as $route) {
            $this->actingAs($this->admin())->get(route($route))->assertOk();
        }
    }

    /** ولا يفتحها أيُّ دورٍ آخر — ولا المدير. */
    public function test_no_other_role_reaches_any_new_screen(): void
    {
        foreach (self::OTHER_ROLES as $role) {
            $user = $this->withRole($role);

            foreach (self::TRIAL_ROUTES as $route) {
                $this->actingAs($user)->get(route($route))
                    ->assertForbidden("الدور {$role} وصل إلى {$route}");
            }
        }
    }

    /**
     * ولا تظهر بنودها في القائمة الجانبية.
     *
     * المنع بلا إخفاءٍ يترك بندًا يوصل إلى 403 — يبدو عطلًا في النظام لا حدًّا
     * في الصلاحية.
     */
    public function test_the_sidebar_hides_them_from_everyone_else(): void
    {
        $hidden = ['طلبات لم تكتمل', 'قوائم أسعار التجّار', 'لوحة قرار الصنف',
            'المبيعات حسب المدن والمناطق', 'الميزانية اليومية'];

        foreach (self::OTHER_ROLES as $role) {
            $titles = $this->navigationTitles($this->withRole($role));

            foreach ($hidden as $title) {
                $this->assertNotContains($title, $titles, "الدور {$role} يرى «{$title}» في القائمة");
            }
        }
    }

    /** ويراها مدير النظام في قائمته. */
    public function test_the_sidebar_shows_them_to_the_system_admin(): void
    {
        $titles = $this->navigationTitles($this->admin());

        foreach (['طلبات لم تكتمل', 'قوائم أسعار التجّار', 'لوحة قرار الصنف'] as $title) {
            $this->assertContains($title, $titles);
        }
    }

    /**
     * والصندوق ووكيل المبيعات محصوران كذلك.
     *
     * يُفحصان بالصلاحية لا بالرابط: شاشاتهما لم تُبنَ بعد، والحصر يجب أن يسبق
     * الشاشة لا أن يلحقها.
     */
    public function test_the_inbox_and_agent_permissions_are_admin_only(): void
    {
        $permissions = ['inbox.view', 'inbox.reply', 'inbox.assign', 'ai_agent.handoff',
            'ai_agent.toggle', 'ai_agent.runs.view', 'ai_agent.knowledge.view', 'ai_agent.knowledge.manage'];

        foreach ($permissions as $permission) {
            $this->assertTrue($this->admin()->can($permission), "مدير النظام لا يملك {$permission}");
        }

        foreach (self::OTHER_ROLES as $role) {
            $user = $this->withRole($role);

            foreach ($permissions as $permission) {
                $this->assertFalse($user->can($permission), "الدور {$role} يملك {$permission}");
            }
        }
    }

    /**
     * والتقارير القديمة لم تُغلَق بالمناسبة.
     *
     * التقريران الجديدان كانا على `reports.sales_summary.view` المشتركة، وفصلُهما
     * عنها كان يجب ألّا يمسّ من يستعمل القديمة.
     */
    public function test_the_older_sales_reports_stay_open_to_their_holders(): void
    {
        $supervisor = $this->withRole('sales_supervisor');

        $this->assertTrue($supervisor->can('reports.sales_summary.view'));
        $this->actingAs($supervisor)->get(route('admin.reports.sales.by_product'))->assertOk();
    }

    /** @return array<int, string> */
    private function navigationTitles(User $user): array
    {
        $this->actingAs($user);

        return collect(AdminNavigation::groups())
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('label'))
            ->all();
    }
}
