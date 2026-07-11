<?php

namespace Tests\Unit\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryLedger;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function variant()
    {
        return Product::factory()->create()->defaultVariant;
    }

    private function warehouse(bool $allowNegative = false): Warehouse
    {
        return Warehouse::factory()->create(['allow_negative' => $allowNegative]);
    }

    public function test_weighted_average_cost_is_precise(): void
    {
        $svc = app(InventoryService::class);
        $v = $this->variant();
        $w = $this->warehouse();

        $svc->receive($v, $w, 3, 10);   // value 30
        $svc->receive($v, $w, 2, 15);   // value 30 -> total 60 / 5 = 12
        $stock = InventoryStock::where('variant_id', $v->id)->first();

        $this->assertEquals(5, $stock->on_hand);
        $this->assertEquals(12, (float) $stock->average_cost);
    }

    public function test_every_operation_writes_movement_and_ledger(): void
    {
        $svc = app(InventoryService::class);
        $v = $this->variant();
        $w = $this->warehouse();

        $svc->receive($v, $w, 10, 5);
        $svc->issue($v, $w, 2);

        $this->assertSame(2, InventoryMovement::count());
        $this->assertSame(2, InventoryLedger::count());
        $this->assertSame('purchase_in', InventoryMovement::orderBy('id')->first()->type);
    }

    public function test_ledger_is_append_only_has_no_updated_at(): void
    {
        $svc = app(InventoryService::class);
        $svc->receive($this->variant(), $this->warehouse(), 5, 5);

        $this->assertFalse(Schema::hasColumn('inventory_ledger', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('inventory_ledger', 'deleted_at'));
    }

    public function test_issue_beyond_available_throws(): void
    {
        $this->expectException(ValidationException::class);
        app(InventoryService::class)->issue($this->variant(), $this->warehouse(), 1);
    }
}
