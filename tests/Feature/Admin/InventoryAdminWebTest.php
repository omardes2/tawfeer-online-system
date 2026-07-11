<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAdminWebTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    public function test_guest_redirected(): void
    {
        $this->get('/admin/inventory/stocks')->assertRedirect('/login');
    }

    public function test_stocks_page_renders_rtl(): void
    {
        $res = $this->actingAs($this->admin())->get('/admin/inventory/stocks');
        $res->assertOk();
        $res->assertSee('dir="rtl"', false);
        $res->assertSee('أرصدة المخزون');
    }

    public function test_admin_can_receive_via_web(): void
    {
        $v = Product::factory()->create()->defaultVariant;

        $this->actingAs($this->admin())
            ->from(route('admin.inventory.operations'))
            ->post(route('admin.inventory.operations.receive'), [
                'variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 10, 'unit_cost' => 5,
            ])->assertRedirect();

        $this->assertEquals(10, InventoryStock::where('variant_id', $v->id)->first()->on_hand);
    }

    public function test_admin_can_create_and_post_adjustment_via_web(): void
    {
        $v = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($v, $this->warehouse, 50, 10);

        $this->actingAs($this->admin())->post(route('admin.inventory.adjustments.store'), [
            'warehouse' => $this->warehouse->uuid,
            'type' => 'recount',
            'items' => [['variant' => $v->uuid, 'qty_counted' => 45]],
        ])->assertRedirect();

        $adj = StockAdjustment::latest('id')->first();
        $this->actingAs($this->admin())->post(route('admin.inventory.adjustments.approve', $adj));
        $this->actingAs($this->admin())->post(route('admin.inventory.adjustments.post', $adj));

        $this->assertEquals(45, InventoryStock::where('variant_id', $v->id)->first()->on_hand);
    }
}
