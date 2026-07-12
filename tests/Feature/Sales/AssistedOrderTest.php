<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\OrderPriceChange;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function variant(float $retail = 100, float $min = 70, float $cost = 60, float $stock = 20): ProductVariant
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $retail, 'min_price' => $min]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $retail, 'min_price' => $min]);
        // استلام مخزون بتكلفة $cost (يضبط average_cost).
        app(InventoryService::class)->receive($variant, $this->warehouse, $stock, $cost);

        return $variant->fresh();
    }

    private function payload(ProductVariant $v, array $item = [], array $over = []): array
    {
        return array_merge([
            'channel' => 'whatsapp',
            'customer_name' => 'عميل واتساب',
            'customer_phone' => '0500000000',
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [array_merge(['variant' => $v->uuid, 'qty' => 2, 'unit_price' => 100], $item)],
        ], $over);
    }

    public function test_requires_permission(): void
    {
        Sanctum::actingAs($this->actor('customer'));
        $this->postJson('/api/v1/sales/assisted-orders', $this->payload($this->variant()))->assertForbidden();
    }

    public function test_creates_order_with_channel_attribution_and_snapshots(): void
    {
        $sales = $this->actor('sales');
        Sanctum::actingAs($sales);
        $v = $this->variant(100, 70, 60);

        $res = $this->postJson('/api/v1/sales/assisted-orders', $this->payload($v))->assertOk();
        $res->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.retail_price_snapshot', '100.00');

        // عميل أُنشئ بالهاتف، وأُسند الطلب للموظف.
        $this->assertDatabaseHas('customers', ['primary_phone' => '0500000000']);
        $this->assertDatabaseHas('orders', ['channel' => 'whatsapp', 'assigned_to' => $sales->id]);
    }

    public function test_price_edit_within_limits_is_auto_approved(): void
    {
        Sanctum::actingAs($this->actor('sales'));
        $v = $this->variant(100, 70, 60);

        // بيع بـ80 (فوق min=70 والتكلفة=60) → auto_approved.
        $this->postJson('/api/v1/sales/assisted-orders', $this->payload($v, ['unit_price' => 80, 'reason' => 'عرض']))->assertOk();

        $change = OrderPriceChange::first();
        $this->assertEquals('auto_approved', $change->status);
        $this->assertNotNull($change->approved_by);
    }

    public function test_price_below_min_requires_approval_for_sales(): void
    {
        Sanctum::actingAs($this->actor('sales'));
        $v = $this->variant(100, 70, 60);

        // بيع بـ65 (تحت min=70) بلا صلاحية تجاوز → pending.
        $this->postJson('/api/v1/sales/assisted-orders', $this->payload($v, ['unit_price' => 65, 'reason' => 'إلحاح']))->assertOk();

        $change = OrderPriceChange::first();
        $this->assertEquals('pending', $change->status);
        $this->assertTrue($change->requires_approval);
        $this->assertNull($change->approved_by);
    }

    public function test_supervisor_override_auto_approves_below_min(): void
    {
        Sanctum::actingAs($this->actor('sales_supervisor'));
        $v = $this->variant(100, 70, 60);

        // مشرف يملك override_price → حتى تحت min يُعتمد آليًا.
        $this->postJson('/api/v1/sales/assisted-orders', $this->payload($v, ['unit_price' => 55]))->assertOk();

        $this->assertEquals('auto_approved', OrderPriceChange::first()->status);
    }

    public function test_supervisor_can_approve_and_reject_pending(): void
    {
        Sanctum::actingAs($this->actor('sales'));
        $v = $this->variant(100, 70, 60);
        $this->postJson('/api/v1/sales/assisted-orders', $this->payload($v, ['unit_price' => 65]))->assertOk();
        $change = OrderPriceChange::first();

        // موظف مبيعات لا يعتمد.
        Sanctum::actingAs($this->actor('sales'));
        $this->postJson("/api/v1/sales/assisted-orders/price-changes/{$change->id}/approve")->assertForbidden();

        // مشرف يعتمد.
        $supervisor = $this->actor('sales_supervisor');
        Sanctum::actingAs($supervisor);
        $this->getJson('/api/v1/sales/assisted-orders/price-changes')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/sales/assisted-orders/price-changes/{$change->id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertEquals($supervisor->id, $change->fresh()->approved_by);

        // لا يُعتمد مرتين.
        $this->postJson("/api/v1/sales/assisted-orders/price-changes/{$change->id}/reject")->assertUnprocessable();
    }

    public function test_existing_customer_reused_by_phone(): void
    {
        Sanctum::actingAs($this->actor('sales'));
        $existing = Customer::factory()->create(['primary_phone' => '0500000000', 'name' => 'قديم']);
        $v = $this->variant();

        $this->postJson('/api/v1/sales/assisted-orders', $this->payload($v))->assertOk();

        // لم يُنشأ عميل جديد بنفس الهاتف.
        $this->assertEquals(1, Customer::where('primary_phone', '0500000000')->count());
        $this->assertDatabaseHas('orders', ['customer_id' => $existing->id]);
    }

    public function test_price_change_log_is_immutable_record(): void
    {
        Sanctum::actingAs($this->actor('sales'));
        $v = $this->variant(100, 70, 60);
        $this->postJson('/api/v1/sales/assisted-orders', $this->payload($v, ['unit_price' => 80, 'reason' => 'خصم']))->assertOk();

        $this->assertDatabaseHas('order_price_changes', [
            'new_price' => 80, 'original_retail' => 100, 'reason' => 'خصم', 'status' => 'auto_approved',
        ]);
    }
}
