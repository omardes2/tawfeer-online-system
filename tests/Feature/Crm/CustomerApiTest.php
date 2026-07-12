<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerApiTest extends TestCase
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

    public function test_create_customer_with_phones_addresses_contacts(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/v1/crm/customers', [
            'name' => 'عميل',
            'primary_phone' => '966 50-123-4567',
            'category' => 'vip',
            'tags' => ['جملة'],
            'phones' => [['phone' => '0555000111', 'is_primary' => true], ['phone' => '0555000222']],
            'addresses' => [['label' => 'المنزل', 'address_line' => 'الرياض', 'is_default' => true]],
            'contacts' => [['name' => 'مساعد', 'is_primary' => true]],
        ])->assertCreated();

        $res->assertJsonPath('data.primary_phone', '966501234567'); // مُطبَّع
        $this->assertCount(2, $res->json('data.phones'));
        $this->assertCount(1, $res->json('data.addresses'));
        $this->assertCount(1, $res->json('data.contacts'));
    }

    public function test_only_one_primary_phone_and_default_address(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/v1/crm/customers', [
            'name' => 'عميل', 'primary_phone' => '0500000000',
            'phones' => [['phone' => '111', 'is_primary' => true], ['phone' => '222', 'is_primary' => true]],
            'addresses' => [['address_line' => 'a', 'is_default' => true], ['address_line' => 'b', 'is_default' => true]],
        ])->assertCreated();

        $this->assertEquals(1, collect($res->json('data.phones'))->where('is_primary', true)->count());
        $this->assertEquals(1, collect($res->json('data.addresses'))->where('is_default', true)->count());
    }

    public function test_duplicate_detection_by_normalized_phone(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/crm/customers', ['name' => 'أول', 'primary_phone' => '966501234567']);

        $res = $this->getJson('/api/v1/crm/customers/duplicates?phone='.urlencode('+96650 123 4567'))->assertOk();
        $this->assertCount(1, $res->json('data'));
    }

    public function test_block_and_unblock(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/crm/customers', ['name' => 'x', 'primary_phone' => '05000'])->json('data.id');

        $this->postJson("/api/v1/crm/customers/{$id}/block", ['reason' => 'احتيال'])
            ->assertOk()->assertJsonPath('data.is_blocked', true);
        $this->postJson("/api/v1/crm/customers/{$id}/unblock")
            ->assertOk()->assertJsonPath('data.is_blocked', false);
    }

    public function test_add_note(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/crm/customers', ['name' => 'x', 'primary_phone' => '05001'])->json('data.id');

        $res = $this->postJson("/api/v1/crm/customers/{$id}/notes", ['body' => 'ملاحظة مهمة'])->assertOk();
        $this->assertCount(1, $res->json('data.notes'));
    }

    public function test_merge_moves_children_and_soft_deletes_source(): void
    {
        Sanctum::actingAs($this->admin());
        $source = Customer::factory()->create();
        $source->phones()->create(['phone' => '111']);
        $target = Customer::factory()->create();

        $this->postJson("/api/v1/crm/customers/{$source->uuid}/merge", ['target' => $target->uuid])->assertOk();

        $this->assertSoftDeleted('customers', ['id' => $source->id]);
        $this->assertEquals($target->id, $source->fresh()->merged_into_id);
        $this->assertEquals(1, $target->phones()->count());
    }

    public function test_delete_is_soft(): void
    {
        Sanctum::actingAs($this->admin());
        $c = Customer::factory()->create();

        $this->deleteJson("/api/v1/crm/customers/{$c->uuid}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $c->id]);
    }

    public function test_sales_can_create_but_not_block(): void
    {
        Sanctum::actingAs($this->withRole('sales'));
        $id = $this->postJson('/api/v1/crm/customers', ['name' => 'x', 'primary_phone' => '05002'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/crm/customers/{$id}/block", ['reason' => 'x'])->assertForbidden();
    }

    public function test_affiliate_read_only(): void
    {
        Sanctum::actingAs($this->withRole('affiliate'));

        $this->getJson('/api/v1/crm/customers')->assertOk();
        $this->postJson('/api/v1/crm/customers', ['name' => 'x', 'primary_phone' => '1'])->assertForbidden();
    }
}
