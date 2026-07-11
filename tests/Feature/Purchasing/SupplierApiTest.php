<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierApiTest extends TestCase
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

    public function test_create_supplier_with_contacts_enforces_single_primary(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/v1/purchasing/suppliers', [
            'name' => 'مورد الاختبار',
            'code' => 'SUP-001',
            'phone' => '0100000000',
            'contacts' => [
                ['name' => 'أحمد', 'is_primary' => true],
                ['name' => 'خالد', 'is_primary' => true],
            ],
        ])->assertCreated();

        $res->assertJsonPath('data.code', 'SUP-001');
        $supplier = Supplier::where('code', 'SUP-001')->firstOrFail();
        $this->assertCount(2, $supplier->contacts);
        $this->assertEquals(1, $supplier->contacts->where('is_primary', true)->count());
    }

    public function test_supplier_code_must_be_unique(): void
    {
        Sanctum::actingAs($this->admin());
        Supplier::factory()->create(['code' => 'DUP-1']);

        $this->postJson('/api/v1/purchasing/suppliers', [
            'name' => 'x', 'code' => 'DUP-1',
        ])->assertUnprocessable();
    }

    public function test_update_and_delete_supplier(): void
    {
        Sanctum::actingAs($this->admin());
        $supplier = Supplier::factory()->create(['code' => 'SUP-9']);

        $this->putJson("/api/v1/purchasing/suppliers/{$supplier->uuid}", ['name' => 'محدّث'])
            ->assertOk()->assertJsonPath('data.name', 'محدّث');

        $this->deleteJson("/api/v1/purchasing/suppliers/{$supplier->uuid}")->assertOk();
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_accountant_can_view_but_not_create(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('accountant');
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/purchasing/suppliers')->assertOk();
        $this->postJson('/api/v1/purchasing/suppliers', ['name' => 'x', 'code' => 'C1'])->assertForbidden();
    }
}
