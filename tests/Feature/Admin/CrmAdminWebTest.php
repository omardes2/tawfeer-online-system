<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmAdminWebTest extends TestCase
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
        $this->get('/admin/crm/customers')->assertRedirect('/login');
    }

    public function test_customers_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/crm/customers');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('العملاء');
    }

    public function test_create_and_show_render(): void
    {
        $this->actingAs($this->admin())->get('/admin/crm/customers/create')->assertOk();
        $customer = Customer::factory()->create();
        $this->actingAs($this->admin())->get("/admin/crm/customers/{$customer->uuid}")->assertOk();
    }

    public function test_affiliate_sees_no_create_button(): void
    {
        $response = $this->actingAs($this->withRole('affiliate'))->get('/admin/crm/customers');
        $response->assertOk();
        $response->assertDontSee(route('admin.crm.customers.create'));
    }

    public function test_affiliate_cannot_open_create(): void
    {
        $this->actingAs($this->withRole('affiliate'))->get('/admin/crm/customers/create')->assertForbidden();
    }
}
