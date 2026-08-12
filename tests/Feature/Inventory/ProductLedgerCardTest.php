<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كرت الصنف يعرض الحركات الفعلية على الكمية فقط (إدخال/صرف/مرتجع)، ويُخفي
 * «حجز» و«تحرير حجز» — إجراءان داخليان على دلو الحجز لا يغيّران الرصيد.
 */
class ProductLedgerCardTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('admin');

        return $u;
    }

    /** استلام 10 ثم بيع 1 — البيع يولّد حجزًا وتحريرًا وصرفًا. */
    private function productWithSale(): Product
    {
        $product = Product::factory()->active()->create(['name' => 'قماش', 'retail_price' => 50]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 50]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 10, 10);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 1, 'unit_price' => 50, 'discount' => 0]], 2026);

        app(OrderService::class)->fulfillToShipped($order);

        return $product->fresh();
    }

    public function test_card_hides_reservation_movements(): void
    {
        $product = $this->productWithSale();

        $res = $this->actingAs($this->admin())
            ->get(route('admin.inventory.products.show', $product))->assertOk();

        $types = collect($res->viewData('ledger')->items())->pluck('movement_type');

        // الحركتان الداخليتان غائبتان…
        $this->assertNotContains('reserve', $types);
        $this->assertNotContains('release', $types);
        // …والحركتان الفعليتان حاضرتان.
        $this->assertContains('purchase_in', $types);
        $this->assertContains('sale_out', $types);
        $this->assertCount(2, $types);
    }

    /** الرصيد بعد كل حركة معروضة يبقى متسلسلًا ومطابقًا للرصيد الفعلي (10 ثم 9). */
    public function test_visible_rows_keep_running_balance_consistent(): void
    {
        $product = $this->productWithSale();

        $res = $this->actingAs($this->admin())
            ->get(route('admin.inventory.products.show', $product))->assertOk();

        $rows = collect($res->viewData('ledger')->items())->sortBy('id')->values();

        $this->assertEqualsWithDelta(10, (float) $rows[0]->balance_after, 0.001); // بعد الشراء
        $this->assertEqualsWithDelta(9, (float) $rows[1]->balance_after, 0.001);  // بعد البيع
        $this->assertEqualsWithDelta(9, (float) $res->viewData('onHand'), 0.001);
    }

    /** المرتجع حركة فعلية ⇒ يظهر في الكرت. */
    public function test_customer_return_appears_in_card(): void
    {
        $product = $this->productWithSale();
        $variant = $product->defaultVariant;

        app(InventoryService::class)->returnToStock($variant, $this->warehouse, 1, 10, [
            'reference_type' => Order::class, 'reference_id' => Order::latest('id')->value('id'),
        ]);

        $res = $this->actingAs($this->admin())
            ->get(route('admin.inventory.products.show', $product))->assertOk();

        $this->assertContains('return_in', collect($res->viewData('ledger')->items())->pluck('movement_type'));
    }
}
