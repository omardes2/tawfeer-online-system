<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
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

    public function test_admin_can_create_employee_with_role_and_profile(): void
    {
        $this->actingAs($this->actor('admin'))->post(route('admin.users.store'), [
            'name' => 'موظّف مبيعات', 'email' => 'emp@tawfeer.test', 'phone' => '966500009999',
            'department' => 'المبيعات', 'job_title' => 'مندوب', 'role' => 'sales',
            'is_active' => 1, 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'emp@tawfeer.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('المبيعات', $user->department);
        $this->assertSame('مندوب', $user->job_title);
        $this->assertTrue($user->hasRole('sales'));
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_update_changes_role_and_optional_password(): void
    {
        $user = $this->actor('sales');
        $this->actingAs($this->actor('admin'))->put(route('admin.users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => 'warehouse',
            'department' => 'المستودع', 'is_active' => 1,
        ])->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertTrue($user->hasRole('warehouse'));
        $this->assertFalse($user->hasRole('sales'));
        $this->assertSame('المستودع', $user->department);
    }

    public function test_toggle_active_and_reset_password(): void
    {
        $admin = $this->actor('admin');
        $user = $this->actor('sales');

        $this->actingAs($admin)->post(route('admin.users.toggle', $user))->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);

        $old = $user->password;
        $this->actingAs($admin)->post(route('admin.users.reset_password', $user))->assertSessionHas('success');
        $this->assertNotSame($old, $user->fresh()->password);
    }

    public function test_cannot_delete_self_or_last_admin(): void
    {
        $admin = $this->actor('admin');

        // آخر مدير — لا يُحذف.
        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertSessionHas('error');
        $this->assertNotNull($admin->fresh());
    }

    public function test_requires_permission(): void
    {
        $this->actingAs($this->actor('sales'))->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_search_and_role_filter(): void
    {
        $admin = $this->actor('admin');
        $this->actor('warehouse'); // another user
        $target = $this->actor('sales');
        $target->update(['name' => 'زيد الفريد']);

        $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'زيد الفريد']))
            ->assertOk()->assertSee('زيد الفريد');
    }
}
