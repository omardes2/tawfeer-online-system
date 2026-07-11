<?php

namespace Tests\Feature\Structure;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchApiTest extends TestCase
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

    public function test_admin_can_list_branches(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/branches')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'code', 'is_default']], 'meta']);
    }

    public function test_admin_can_create_branch(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/branches', ['name' => 'فرع الرياض', 'code' => 'RUH'])
            ->assertCreated()->assertJsonPath('data.code', 'RUH');

        $this->assertDatabaseHas('branches', ['code' => 'RUH']);
    }

    public function test_branch_code_must_be_unique(): void
    {
        Sanctum::actingAs($this->admin());
        Branch::factory()->create(['code' => 'DUPB']);

        $this->postJson('/api/v1/branches', ['name' => 'مكرر', 'code' => 'DUPB'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_setting_new_default_branch_unsets_old(): void
    {
        Sanctum::actingAs($this->admin());
        $old = Branch::default();

        $this->postJson('/api/v1/branches', ['name' => 'افتراضي جديد', 'code' => 'NEWDEF', 'is_default' => true])
            ->assertCreated();

        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, Branch::where('is_default', true)->count());
    }

    public function test_cannot_delete_default_branch(): void
    {
        Sanctum::actingAs($this->admin());

        $this->deleteJson('/api/v1/branches/'.Branch::default()->uuid)
            ->assertStatus(422);
    }

    public function test_can_soft_delete_non_default_branch(): void
    {
        Sanctum::actingAs($this->admin());
        $branch = Branch::factory()->create();

        $this->deleteJson('/api/v1/branches/'.$branch->uuid)->assertOk();
        $this->assertSoftDeleted($branch);
    }

    public function test_sales_cannot_access_branch_management(): void
    {
        $sales = User::factory()->create(['branch_id' => Branch::default()->id]);
        $sales->assignRole('sales');
        Sanctum::actingAs($sales);

        $this->getJson('/api/v1/branches')->assertForbidden();
    }
}
