<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    public function test_guest_redirected(): void
    {
        $this->get('/admin/reports/analytics')->assertRedirect('/login');
    }

    /** كل صفحات وحدة التقارير والتحليلات تُفتح للأدمِن (200) وبتصميم RTL. */
    public function test_all_category_pages_render_for_admin(): void
    {
        $routes = [
            'index', 'dashboard', 'sales', 'customers', 'products', 'inventory',
            'shipping', 'financial', 'employees', 'marketers', 'marketing', 'support', 'audit',
        ];

        foreach ($routes as $r) {
            $res = $this->actingAs($this->admin())->get(route('admin.reports.analytics.'.$r));
            $res->assertOk();
            $res->assertSee('dir="rtl"', false);
        }
    }

    public function test_dashboard_shows_executive_kpis(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.reports.analytics.dashboard'));
        $res->assertOk();
        $res->assertSee(__('اللوحة التنفيذية'));
        $res->assertSee(__('صافي الربح'));
        $res->assertSee(__('مبيعات اليوم'));
    }

    public function test_csv_export_streams_download(): void
    {
        $res = $this->actingAs($this->admin())
            ->get(route('admin.reports.analytics.sales', ['export' => 'csv']));
        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_category_permission_gates_access(): void
    {
        // مستخدم بلا صلاحيات التقارير الدقيقة يُمنع من فئة تتطلّب can محدّدة.
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->givePermissionTo('reports.view'); // يملك دخول المجموعة لكن ليس الفئة

        $this->actingAs($user)->get(route('admin.reports.analytics.financial'))->assertForbidden();
    }

    public function test_manager_can_access_all_categories(): void
    {
        $manager = User::factory()->create(['branch_id' => Branch::default()->id]);
        $manager->assignRole('manager');

        $this->actingAs($manager)->get(route('admin.reports.analytics.financial'))->assertOk();
        $this->actingAs($manager)->get(route('admin.reports.analytics.audit'))->assertOk();
    }
}
