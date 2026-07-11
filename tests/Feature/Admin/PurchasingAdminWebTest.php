<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingAdminWebTest extends TestCase
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
        $this->get('/admin/purchasing/suppliers')->assertRedirect('/login');
    }

    public function test_suppliers_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/purchasing/suppliers');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('الموردون');
    }

    public function test_orders_index_and_create_render(): void
    {
        $this->actingAs($this->admin())->get('/admin/purchasing/orders')->assertOk()->assertSee('أوامر الشراء');
        $this->actingAs($this->admin())->get('/admin/purchasing/orders/create')->assertOk();
    }

    public function test_receipts_and_returns_index_render(): void
    {
        $this->actingAs($this->admin())->get('/admin/purchasing/receipts')->assertOk()->assertSee('إيصالات الاستلام');
        $this->actingAs($this->admin())->get('/admin/purchasing/returns')->assertOk()->assertSee('مرتجعات الموردين');
    }

    public function test_accountant_sees_no_create_button_on_suppliers(): void
    {
        Supplier::factory()->create();
        $response = $this->actingAs($this->withRole('accountant'))->get('/admin/purchasing/suppliers');
        $response->assertOk();
        $response->assertDontSee(route('admin.purchasing.suppliers.create'));
    }

    public function test_accountant_cannot_open_supplier_create_form(): void
    {
        $this->actingAs($this->withRole('accountant'))->get('/admin/purchasing/suppliers/create')->assertForbidden();
    }
}
