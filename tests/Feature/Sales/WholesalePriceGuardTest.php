<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WholesalePriceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    /** متغيّر بسعر جملة 70 وبيع 100، مع مخزون كافٍ. */
    private function variant(): ProductVariant
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        $variant->update(['wholesale_price' => 70, 'retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $warehouse, 20, 50);

        return $variant;
    }

    private function order(array $item, string $channel = 'pos'): Order
    {
        return app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'customer_name' => 'عميل', 'customer_phone' => '0599000000', 'channel' => $channel,
        ], [$item], 2026);
    }

    public function test_blocks_sale_below_wholesale_price(): void
    {
        $v = $this->variant();
        $this->expectException(ValidationException::class);
        $this->order(['variant_id' => $v->id, 'qty' => 1, 'unit_price' => 40]);
    }

    public function test_allows_sale_at_or_above_wholesale(): void
    {
        $v = $this->variant();
        $order = $this->order(['variant_id' => $v->id, 'qty' => 2, 'unit_price' => 70]); // عند سعر الجملة تمامًا
        $this->assertNotNull($order->id);
        $this->assertEqualsWithDelta(140, (float) $order->fresh()->total, 0.01);
    }

    public function test_discount_cannot_drop_net_unit_below_wholesale(): void
    {
        $v = $this->variant();
        // 100 سعر الوحدة، خصم 40 على كمية 1 ⇒ صافي 60 < 70 ⇒ يُمنع.
        $this->expectException(ValidationException::class);
        $this->order(['variant_id' => $v->id, 'qty' => 1, 'unit_price' => 100, 'discount' => 40]);
    }

    public function test_guard_applies_to_delivery_channel_too(): void
    {
        $v = $this->variant();
        $this->expectException(ValidationException::class);
        $this->order(['variant_id' => $v->id, 'qty' => 1, 'unit_price' => 50], 'manual');
    }

    public function test_no_wholesale_set_means_no_restriction(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant; // بلا سعر جملة
        app(InventoryService::class)->receive($variant, $warehouse, 5, 10);

        $order = $this->order(['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 1]);
        $this->assertNotNull($order->id);
    }
}
