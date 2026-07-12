<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAdminWebTest extends TestCase
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
        $this->get('/admin/payments')->assertRedirect('/login');
    }

    public function test_payments_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/payments');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('المدفوعات');
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/payments/create')->assertOk();
    }

    public function test_warehouse_cannot_open_create_but_can_view(): void
    {
        // المستودع: view + capture (تحصيل COD)، لا create.
        $this->actingAs($this->withRole('warehouse'))->get('/admin/payments')->assertOk();
        $this->actingAs($this->withRole('warehouse'))->get('/admin/payments/create')->assertForbidden();
    }

    public function test_affiliate_cannot_view_payments(): void
    {
        $this->actingAs($this->withRole('affiliate'))->get('/admin/payments')->assertForbidden();
    }
}
