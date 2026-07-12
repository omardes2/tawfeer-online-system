<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingAdminWebTest extends TestCase
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
        $this->get('/admin/shipping/shipments')->assertRedirect('/login');
    }

    public function test_shipments_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/shipping/shipments');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('الشحنات');
    }

    public function test_geography_index_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/shipping/geography')
            ->assertOk()->assertSee('المحافظات والمدن');
    }

    public function test_create_lists_shippable_orders(): void
    {
        $this->actingAs($this->admin())->get('/admin/shipping/shipments/create')->assertOk();
    }

    public function test_accountant_cannot_open_create(): void
    {
        // المحاسب يملك view فقط، لا create.
        $this->actingAs($this->withRole('accountant'))->get('/admin/shipping/shipments/create')->assertForbidden();
    }

    public function test_affiliate_cannot_view_shipments(): void
    {
        $this->actingAs($this->withRole('affiliate'))->get('/admin/shipping/shipments')->assertForbidden();
    }
}
