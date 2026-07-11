<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAdminWebTest extends TestCase
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

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin/sales/orders')->assertRedirect('/login');
    }

    public function test_orders_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/sales/orders');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('طلبات البيع');
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/sales/orders/create')->assertOk()->assertSee('طلب بيع جديد');
    }

    public function test_accountant_sees_no_create_button(): void
    {
        $response = $this->actingAs($this->withRole('accountant'))->get('/admin/sales/orders');
        $response->assertOk();
        $response->assertDontSee(route('admin.sales.orders.create'));
    }

    public function test_accountant_cannot_open_create_form(): void
    {
        $this->actingAs($this->withRole('accountant'))->get('/admin/sales/orders/create')->assertForbidden();
    }
}
