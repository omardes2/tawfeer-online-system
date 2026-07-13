<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('admin');

        return $u;
    }

    public function test_create_role_with_permissions(): void
    {
        $this->actingAs($this->admin())->post(route('admin.roles.store'), [
            'name' => 'support_agent',
            'permissions' => ['crm.customers.view', 'reports.view'],
        ])->assertRedirect(route('admin.roles.index'));

        $role = Role::findByName('support_agent');
        $this->assertTrue($role->hasPermissionTo('crm.customers.view'));
        $this->assertTrue($role->hasPermissionTo('reports.view'));
    }

    public function test_copy_role_duplicates_permissions(): void
    {
        $admin = $this->admin();
        $source = Role::findByName('sales');
        $this->actingAs($admin)->post(route('admin.roles.copy', $source), ['name' => 'sales_copy'])
            ->assertRedirect(route('admin.roles.index'));

        $copy = Role::findByName('sales_copy');
        $this->assertEqualsCanonicalizing(
            $source->permissions->pluck('name')->all(),
            $copy->permissions->pluck('name')->all()
        );
    }

    public function test_cannot_delete_protected_admin_role(): void
    {
        $this->actingAs($this->admin())->delete(route('admin.roles.destroy', Role::findByName('admin')))
            ->assertSessionHasErrors();
        $this->assertNotNull(Role::findByName('admin'));
    }

    public function test_cannot_delete_role_with_users(): void
    {
        $admin = $this->admin();
        $role = Role::create(['name' => 'temp_role', 'guard_name' => 'web']);
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('temp_role');

        $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertSessionHasErrors();
        $this->assertNotNull(Role::find($role->id));
    }

    public function test_delete_empty_custom_role(): void
    {
        $admin = $this->admin();
        $role = Role::create(['name' => 'disposable', 'guard_name' => 'web']);
        $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertRedirect();
        $this->assertNull(Role::find($role->id));
    }

    public function test_requires_permission(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        $this->actingAs($u)->get(route('admin.roles.index'))->assertForbidden();
    }
}
