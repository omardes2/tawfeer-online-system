<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryBatchService;
use App\Modules\Inventory\Services\InventoryCountService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseCountTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private InventoryCountService $counts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->counts = app(InventoryCountService::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function variant(float $stock = 50, float $cost = 60, ?string $barcode = null): ProductVariant
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible']);
        $variant = $product->defaultVariant;
        if ($barcode) {
            $variant->update(['barcode' => $barcode]);
        }
        app(InventoryService::class)->receive($variant, $this->warehouse, $stock, $cost);

        return $variant->fresh();
    }

    private function onHand(ProductVariant $v): float
    {
        return (float) InventoryStock::where('variant_id', $v->id)->where('warehouse_id', $this->warehouse->id)->value('on_hand');
    }

    // ---- الجرد الفعلي ----

    public function test_count_snapshots_system_qty(): void
    {
        $v = $this->variant(stock: 50);
        $count = $this->counts->open($this->warehouse, [$v->id], 'partial', $this->actor('warehouse'));

        $item = $count->items->firstWhere('variant_id', $v->id);
        $this->assertEquals('50.000', $item->system_qty);
        $this->assertEquals('counting', $count->status);
    }

    public function test_count_complete_applies_variance_via_inventory_engine(): void
    {
        $v = $this->variant(stock: 50);
        $count = $this->counts->open($this->warehouse, [$v->id], 'partial', $this->actor('warehouse'));
        $this->counts->recordCount($count, $v->id, 45); // فرق −5
        $this->counts->review($count);
        $this->counts->complete($count, $this->actor('warehouse'));

        $this->assertEquals('completed', $count->fresh()->status);
        $this->assertEquals(45.0, $this->onHand($v)); // طُبّق عبر المحرّك
        // حركة مخزون مسجَّلة (لا مساس مباشر بالأرصدة).
        $this->assertEquals(1, InventoryMovement::where('type', 'adjustment_out')->where('variant_id', $v->id)
            ->where('reference_type', InventoryCount::class)->count());
    }

    public function test_count_increase_variance(): void
    {
        $v = $this->variant(stock: 50);
        $count = $this->counts->open($this->warehouse, [$v->id], 'partial', $this->actor('warehouse'));
        $this->counts->recordCount($count, $v->id, 55); // +5
        $this->counts->review($count);
        $this->counts->complete($count, $this->actor('warehouse'));

        $this->assertEquals(55.0, $this->onHand($v));
        $this->assertEquals(1, $count->fresh()->adjusted_count);
    }

    public function test_zero_variance_applies_nothing(): void
    {
        $v = $this->variant(stock: 50);
        $count = $this->counts->open($this->warehouse, [$v->id], 'partial', $this->actor('warehouse'));
        $this->counts->recordCount($count, $v->id, 50); // لا فرق
        $this->counts->review($count);
        $this->counts->complete($count, $this->actor('warehouse'));

        $this->assertEquals(50.0, $this->onHand($v));
        $this->assertEquals(0, $count->fresh()->adjusted_count);
    }

    public function test_cannot_record_after_review(): void
    {
        $v = $this->variant();
        $count = $this->counts->open($this->warehouse, [$v->id], 'partial');
        $this->counts->review($count);

        $this->expectException(ValidationException::class);
        $this->counts->recordCount($count, $v->id, 10);
    }

    // ---- الباركود ----

    public function test_barcode_lookup_and_record(): void
    {
        $v = $this->variant(stock: 50, barcode: 'BC-123');
        $ws = app(WarehouseService::class);
        $this->assertEquals($v->id, $ws->findByBarcode('BC-123')?->id);

        $count = $this->counts->open($this->warehouse, [$v->id], 'partial');
        $this->counts->recordByBarcode($count, 'BC-123', 48);
        $this->assertEquals('48.000', $count->items->firstWhere('variant_id', $v->id)->fresh()->counted_qty);
    }

    public function test_unknown_barcode_is_rejected(): void
    {
        $count = $this->counts->open($this->warehouse, [], 'full');
        $this->expectException(ValidationException::class);
        $this->counts->recordByBarcode($count, 'NOPE', 1);
    }

    // ---- تنبيهات النقص ----

    public function test_low_stock_alert_uses_reorder_level(): void
    {
        $v = $this->variant(stock: 5);
        InventoryStock::where('variant_id', $v->id)->where('warehouse_id', $this->warehouse->id)->update(['reorder_level' => 10]);

        $rows = app(WarehouseService::class)->lowStock($this->warehouse);
        $this->assertTrue($rows->contains('variant_id', $v->id));
        $this->assertEquals(1, app(WarehouseService::class)->lowStockCount($this->warehouse));
    }

    // ---- الدفعات ----

    public function test_batch_adjust_applies_deltas_and_logs_movements(): void
    {
        $a = $this->variant(stock: 50);
        $b = $this->variant(stock: 30);
        $applied = app(InventoryBatchService::class)->adjust($this->warehouse, [
            ['variant_id' => $a->id, 'delta' => 3],
            ['variant_id' => $b->id, 'delta' => -2],
        ], 'batch recount');

        $this->assertEquals(2, $applied);
        $this->assertEquals(53.0, $this->onHand($a));
        $this->assertEquals(28.0, $this->onHand($b));
        $this->assertEquals(1, InventoryMovement::where('type', 'adjustment_in')->where('variant_id', $a->id)->count());
    }

    // ---- الواجهة والتفويض ----

    public function test_warehouse_dashboard_kpis(): void
    {
        $this->variant(stock: 50);
        $kpi = app(WarehouseService::class)->dashboard($this->warehouse);
        $this->assertGreaterThanOrEqual(1, $kpi['skus']);
        $this->assertEquals(50.0, $kpi['on_hand']);
    }

    public function test_count_authorization_via_api(): void
    {
        Sanctum::actingAs($this->actor('sales')); // لا يملك counts.manage
        $this->postJson('/api/v1/inventory/counts')->assertForbidden();

        Sanctum::actingAs($this->actor('warehouse'));
        $this->postJson('/api/v1/inventory/counts', ['scope' => 'full'])->assertCreated();
    }

    public function test_admin_pages_render(): void
    {
        $wh = $this->actor('warehouse');
        $wh->update(['email_verified_at' => now()]);
        $v = $this->variant();
        $count = $this->counts->open($this->warehouse, [$v->id], 'partial');

        $this->actingAs($wh)->get('/admin/inventory/warehouse')->assertOk()->assertSee(__('warehouse.dashboard'));
        $this->actingAs($wh)->get('/admin/inventory/low-stock')->assertOk();
        $this->actingAs($wh)->get('/admin/inventory/counts')->assertOk();
        $this->actingAs($wh)->get("/admin/inventory/counts/{$count->uuid}")->assertOk()->assertSee($count->number);
    }
}
