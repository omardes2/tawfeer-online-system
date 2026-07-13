<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_admin_dashboard_renders(): void
    {
        $this->actingAs($this->actor('admin'))->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(__('dashboard.title'))
            ->assertSee(__('dashboard.warehouse'));
    }

    public function test_manager_can_view_dashboard(): void
    {
        $this->actingAs($this->actor('manager'))->get(route('admin.dashboard'))->assertOk();
    }

    public function test_forbidden_without_permission(): void
    {
        // دور بلا أي صلاحية — لا يملك dashboard.view (كل الأدوار الموظّفة القياسية تملكها).
        $role = Role::create(['name' => 'no_access', 'guard_name' => 'web']);
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        $this->actingAs($u)->get(route('admin.dashboard'))->assertForbidden();
    }
}
