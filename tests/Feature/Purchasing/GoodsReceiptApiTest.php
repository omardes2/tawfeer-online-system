<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoodsReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->supplier = Supplier::factory()->create();
        $this->variant = Product::factory()->create()->defaultVariant;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function approvedOrder(float $qty = 100, float $cost = 10): PurchaseOrder
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/purchasing/orders', [
            'supplier' => $this->supplier->uuid,
            'warehouse' => $this->warehouse->uuid,
            'items' => [['variant' => $this->variant->uuid, 'qty_ordered' => $qty, 'unit_cost' => $cost]],
        ])->json('data.id');
        $this->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertOk();

        return PurchaseOrder::where('uuid', $id)->firstOrFail();
    }

    public function test_cannot_receive_before_po_approved(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/purchasing/orders', [
            'supplier' => $this->supplier->uuid,
            'warehouse' => $this->warehouse->uuid,
            'items' => [['variant' => $this->variant->uuid, 'qty_ordered' => 10, 'unit_cost' => 10]],
        ])->json('data.id');

        $this->postJson('/api/v1/purchasing/receipts', [
            'purchase_order' => $id,
            'items' => [['variant' => $this->variant->uuid, 'qty_received' => 10, 'unit_cost' => 10]],
        ])->assertUnprocessable();
    }

    public function test_posting_receipt_increases_stock_through_engine_with_landed_cost(): void
    {
        $po = $this->approvedOrder(100, 10);
        $poItem = $po->items()->first();

        $receiptId = $this->postJson('/api/v1/purchasing/receipts', [
            'purchase_order' => $po->uuid,
            'additional_cost' => 120,
            'items' => [[
                'variant' => $this->variant->uuid,
                'purchase_order_item_id' => $poItem->id,
                'qty_received' => 60,
                'unit_cost' => 10,
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchasing/receipts/{$receiptId}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        // المخزون زاد عبر المحرّك، بتكلفة محمّلة (10 + 120/60 = 12).
        $stock = InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(60, (float) $stock->on_hand);
        $this->assertEquals(12.0, (float) $stock->average_cost);

        // حركة purchase_in مكتوبة.
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $this->variant->id,
            'type' => 'purchase_in',
        ]);

        // أمر الشراء أصبح مستلمًا جزئيًا والكمية المستلمة حُدّثت.
        $this->assertEquals('partially_received', $po->fresh()->status);
        $this->assertEquals(60, (float) $poItem->fresh()->qty_received);
    }

    public function test_full_receipt_marks_order_received(): void
    {
        $po = $this->approvedOrder(50, 8);
        $poItem = $po->items()->first();

        $receiptId = $this->postJson('/api/v1/purchasing/receipts', [
            'purchase_order' => $po->uuid,
            'items' => [[
                'variant' => $this->variant->uuid,
                'purchase_order_item_id' => $poItem->id,
                'qty_received' => 50,
                'unit_cost' => 8,
            ]],
        ])->json('data.id');
        $this->postJson("/api/v1/purchasing/receipts/{$receiptId}/post")->assertOk();

        $this->assertEquals('received', $po->fresh()->status);
    }

    public function test_rejects_po_item_from_a_different_order(): void
    {
        $po = $this->approvedOrder(50, 8);
        $otherPo = $this->approvedOrder(50, 8);
        $foreignItemId = $otherPo->items()->first()->id;

        $this->postJson('/api/v1/purchasing/receipts', [
            'purchase_order' => $po->uuid,
            'items' => [[
                'variant' => $this->variant->uuid,
                'purchase_order_item_id' => $foreignItemId,
                'qty_received' => 10,
                'unit_cost' => 8,
            ]],
        ])->assertUnprocessable();
    }

    public function test_cannot_post_twice(): void
    {
        $po = $this->approvedOrder(10, 5);
        $poItem = $po->items()->first();
        $receiptId = $this->postJson('/api/v1/purchasing/receipts', [
            'purchase_order' => $po->uuid,
            'items' => [['variant' => $this->variant->uuid, 'purchase_order_item_id' => $poItem->id, 'qty_received' => 10, 'unit_cost' => 5]],
        ])->json('data.id');
        $this->postJson("/api/v1/purchasing/receipts/{$receiptId}/post")->assertOk();

        $this->postJson("/api/v1/purchasing/receipts/{$receiptId}/post")->assertUnprocessable();
        $this->assertEquals(1, InventoryMovement::where('variant_id', $this->variant->id)->where('type', 'purchase_in')->count());
    }
}
