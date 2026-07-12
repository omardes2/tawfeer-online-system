<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingAdminWebTest extends TestCase
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
        $this->get('/admin/accounting/journal')->assertRedirect('/login');
    }

    public function test_journal_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/accounting/journal');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('قيود اليومية');
    }

    public function test_chart_and_trial_balance_render(): void
    {
        $this->actingAs($this->admin())->get('/admin/accounting/accounts')->assertOk()->assertSee('دليل الحسابات');
        $this->actingAs($this->admin())->get('/admin/accounting/reports/trial-balance')->assertOk()->assertSee('ميزان المراجعة');
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/accounting/journal/create')->assertOk();
    }

    public function test_accountant_can_view_journal(): void
    {
        $this->actingAs($this->withRole('accountant'))->get('/admin/accounting/journal')->assertOk();
    }

    public function test_sales_cannot_view_accounting(): void
    {
        $this->actingAs($this->withRole('sales'))->get('/admin/accounting/journal')->assertForbidden();
    }
}
