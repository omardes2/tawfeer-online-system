<?php

namespace Tests\Feature\Structure;

use App\Models\User;
use App\Modules\Foundation\Models\AuditLog;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseApiTest extends TestCase
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

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_unauthenticated_cannot_list_warehouses(): void
    {
        $this->getJson('/api/v1/warehouses')->assertUnauthorized();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));
        $this->getJson('/api/v1/warehouses')->assertForbidden();
    }

    public function test_admin_can_list_warehouses_and_sees_uuid_not_id(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/v1/warehouses')->assertOk();

        $response->assertJsonStructure(['data' => [['id', 'name', 'code', 'type', 'is_default', 'branch']], 'meta', 'links']);
        // المُعرّف المكشوف هو uuid لا id الداخلي (ADR-002).
        $warehouse = Warehouse::first();
        $response->assertJsonPath('data.0.id', $warehouse->uuid);
        $this->assertNotEquals($warehouse->id, $response->json('data.0.id'));
    }

    public function test_can_filter_by_branch(): void
    {
        Sanctum::actingAs($this->admin());
        $otherBranch = Branch::factory()->create();
        Warehouse::factory()->create(['branch_id' => $otherBranch->id]);

        $response = $this->getJson('/api/v1/warehouses?branch='.$otherBranch->uuid)->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_create_warehouse_and_it_is_audited(): void
    {
        Sanctum::actingAs($this->admin());
        $branch = Branch::default();
        $before = AuditLog::where('auditable_type', Warehouse::class)->count();

        $response = $this->postJson('/api/v1/warehouses', [
            'branch' => $branch->uuid,
            'name' => 'مستودع جدة',
            'code' => 'WH-JED',
            'type' => 'main',
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'مستودع جدة');
        $this->assertDatabaseHas('warehouses', ['code' => 'WH-JED', 'branch_id' => $branch->id]);
        $this->assertGreaterThan($before, AuditLog::where('auditable_type', Warehouse::class)->count());
    }

    public function test_create_requires_name_and_valid_branch(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/warehouses', ['code' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch', 'name']);
    }

    public function test_code_must_be_unique_within_branch(): void
    {
        Sanctum::actingAs($this->admin());
        $branch = Branch::default();
        Warehouse::factory()->create(['branch_id' => $branch->id, 'code' => 'DUP']);

        $this->postJson('/api/v1/warehouses', [
            'branch' => $branch->uuid,
            'name' => 'مكرر',
            'code' => 'DUP',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_same_code_allowed_in_different_branches(): void
    {
        Sanctum::actingAs($this->admin());
        $branchA = Branch::default();
        $branchB = Branch::factory()->create();
        Warehouse::factory()->create(['branch_id' => $branchA->id, 'code' => 'SHARED']);

        $this->postJson('/api/v1/warehouses', [
            'branch' => $branchB->uuid,
            'name' => 'مسموح',
            'code' => 'SHARED',
        ])->assertCreated();
    }

    public function test_setting_default_unsets_other_defaults_in_branch(): void
    {
        Sanctum::actingAs($this->admin());
        $branch = Branch::default();
        $oldDefault = Warehouse::where('branch_id', $branch->id)->where('is_default', true)->first();
        $this->assertNotNull($oldDefault);

        $this->postJson('/api/v1/warehouses', [
            'branch' => $branch->uuid,
            'name' => 'افتراضي جديد',
            'code' => 'WH-NEW',
            'is_default' => true,
        ])->assertCreated();

        $this->assertFalse($oldDefault->fresh()->is_default);
        $this->assertSame(1, Warehouse::where('branch_id', $branch->id)->where('is_default', true)->count());
    }

    public function test_admin_can_update_warehouse(): void
    {
        Sanctum::actingAs($this->admin());
        $warehouse = Warehouse::factory()->create(['branch_id' => Branch::default()->id]);

        $this->patchJson('/api/v1/warehouses/'.$warehouse->uuid, ['name' => 'اسم محدّث'])
            ->assertOk()
            ->assertJsonPath('data.name', 'اسم محدّث');

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'name' => 'اسم محدّث']);
    }

    public function test_admin_can_soft_delete_warehouse_and_clears_branch_default(): void
    {
        Sanctum::actingAs($this->admin());
        $branch = Branch::default();
        $warehouse = $branch->defaultWarehouse;

        $this->deleteJson('/api/v1/warehouses/'.$warehouse->uuid)->assertOk();

        $this->assertSoftDeleted($warehouse);
        $this->assertNull($branch->fresh()->default_warehouse_id);
    }

    public function test_sales_cannot_create_warehouse(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));

        $this->postJson('/api/v1/warehouses', [
            'branch' => Branch::default()->uuid,
            'name' => 'ممنوع',
            'code' => 'WH-NO',
        ])->assertForbidden();
    }
}
