<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Support\AdminNavigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شجرة التنقّل تتقلّص مع صلاحيات المستخدم: مدير النظام يرى كل الأقسام، وموظف
 * المبيعات ما يخصّه فقط — فلا يصل أحد إلى وجهة عبر القائمة لا يملك صلاحيتها.
 */
class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    /** كل عناوين العناصر في الشجرة، مسطّحة. */
    private function labels(array $groups): array
    {
        return collect($groups)->flatMap(fn ($g) => array_column($g['items'], 'label'))->all();
    }

    public function test_admin_sees_every_section(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $groups = AdminNavigation::groups();
        $titles = array_column($groups, 'label');

        foreach (['المبيعات', 'المنتجات', 'المالية والمحاسبة', 'التقارير', 'الإعدادات'] as $expected) {
            $this->assertContains($expected, $titles);
        }

        $this->assertContains('القيود اليومية', $this->labels($groups));
    }

    public function test_sales_role_sees_only_its_own_destinations(): void
    {
        $this->actingAs($this->userWithRole('sales'));

        $groups = AdminNavigation::groups();
        $labels = $this->labels($groups);

        $this->assertContains('الطلبات', $labels);

        // لا محاسبة ولا إدارة أدوار لموظف المبيعات.
        $this->assertNotContains('القيود اليومية', $labels);
        $this->assertNotContains('الأدوار والصلاحيات', $labels);
        $this->assertNotContains('إعدادات النظام', $labels);
    }

    public function test_sales_tree_is_much_smaller_than_admin_tree(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        $adminCount = count($this->labels(AdminNavigation::groups()));

        $this->actingAs($this->userWithRole('sales'));
        $salesCount = count($this->labels(AdminNavigation::groups()));

        $this->assertGreaterThan($salesCount * 2, $adminCount);
    }

    /** القسم الذي لا يبقى فيه عنصر مرئي يُحذف بالكامل بدل أن يظهر فارغًا. */
    public function test_empty_groups_are_dropped(): void
    {
        $this->actingAs($this->userWithRole('sales'));

        foreach (AdminNavigation::groups() as $group) {
            $this->assertNotEmpty($group['items'], "القسم «{$group['label']}» ظهر بلا عناصر.");
        }
    }

    /** الروابط التي تتقاسم مسارًا وتفترق بمعامل لا تُضاء كلّها معًا. */
    public function test_voucher_links_sharing_a_route_highlight_separately(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.accounting.vouchers.index', ['receipt']));

        $finance = collect(AdminNavigation::groups())->firstWhere('label', 'المالية والمحاسبة');
        $vouchers = collect($finance['items'])->whereIn('label', ['سندات القبض', 'سندات الصرف', 'المصروفات']);

        $this->assertCount(1, $vouchers->where('active', true),
            'أكثر من رابط سندات أُضيء في وقت واحد.');
    }

    public function test_active_group_index_points_at_the_current_page(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.sales.orders.index'));

        $groups = AdminNavigation::groups();
        $index = AdminNavigation::activeGroupIndex($groups);

        $this->assertSame('المبيعات', $groups[$index]['label']);
    }
}
