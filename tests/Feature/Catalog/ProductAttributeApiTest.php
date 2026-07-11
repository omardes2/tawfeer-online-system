<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductAttributeApiTest extends TestCase
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

    public function test_admin_can_create_attribute(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/attributes', ['name' => 'اللون', 'type' => 'color'])
            ->assertCreated()->assertJsonPath('data.type', 'color');
    }

    public function test_invalid_type_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/attributes', ['name' => 'x', 'type' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_sales_cannot_create_attribute(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/attributes', ['name' => 'x'])->assertForbidden();
    }

    public function test_can_add_values_and_enforce_unique_per_attribute(): void
    {
        Sanctum::actingAs($this->admin());
        $attr = ProductAttribute::factory()->create();

        $this->postJson("/api/v1/attributes/{$attr->id}/values", ['value' => 'أحمر', 'color_hex' => '#FF0000'])
            ->assertCreated()->assertJsonPath('data.value', 'أحمر');

        // تكرار نفس القيمة لنفس السمة مرفوض
        $this->postJson("/api/v1/attributes/{$attr->id}/values", ['value' => 'أحمر'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
    }

    public function test_same_value_allowed_across_different_attributes(): void
    {
        Sanctum::actingAs($this->admin());
        $a1 = ProductAttribute::factory()->create();
        $a2 = ProductAttribute::factory()->create();
        ProductAttributeValue::factory()->create(['attribute_id' => $a1->id, 'value' => 'S']);

        $this->postJson("/api/v1/attributes/{$a2->id}/values", ['value' => 'S'])->assertCreated();
    }

    public function test_invalid_color_hex_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $attr = ProductAttribute::factory()->create();
        $this->postJson("/api/v1/attributes/{$attr->id}/values", ['value' => 'x', 'color_hex' => 'red'])
            ->assertUnprocessable()->assertJsonValidationErrors('color_hex');
    }

    public function test_deleting_attribute_cascades_values(): void
    {
        Sanctum::actingAs($this->admin());
        $attr = ProductAttribute::factory()->create();
        $val = ProductAttributeValue::factory()->create(['attribute_id' => $attr->id]);

        $this->deleteJson('/api/v1/attributes/'.$attr->id)->assertOk();
        $this->assertDatabaseMissing('product_attribute_values', ['id' => $val->id]);
    }

    public function test_scoped_binding_rejects_value_from_other_attribute(): void
    {
        Sanctum::actingAs($this->admin());
        $a1 = ProductAttribute::factory()->create();
        $a2 = ProductAttribute::factory()->create();
        $v2 = ProductAttributeValue::factory()->create(['attribute_id' => $a2->id]);

        $this->getJson("/api/v1/attributes/{$a1->id}/values/{$v2->id}")->assertNotFound();
    }
}
