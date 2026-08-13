<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تنبيهات نقص المخزون.
 *
 * كان الشرط `inventory_stocks.reorder_level IS NOT NULL`، وهذا الحقل لا تكتبه أي
 * شاشة ولا يُملأ عند إنشاء سطر المخزون — فكانت الصفحة فارغة دائمًا مهما بلغ النقص.
 * الآن يُقارَن المتوفّر بحدٍّ فعّال: سطر المخزون ← المتغيّر ← المنتج ← الافتراضي.
 */
class LowStockAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::where('is_default', true)->firstOrFail();
    }

    /** سطر مخزون بكمية محدّدة، بلا أي حدّ مضبوط (كما تُنشئه الخدمة فعلًا). */
    private function stock(float $onHand, array $overrides = []): InventoryStock
    {
        $product = Product::factory()->create($overrides['product'] ?? []);
        $variant = $product->defaultVariant ?? ProductVariant::factory()->create(['product_id' => $product->id]);

        if (isset($overrides['variant_level'])) {
            $variant->update(['reorder_level' => $overrides['variant_level']]);
        }

        return InventoryStock::create([
            'variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse()->id,
            'on_hand' => $onHand,
            'reorder_level' => $overrides['stock_level'] ?? null,
        ]);
    }

    private function service(): WarehouseService
    {
        return app(WarehouseService::class);
    }

    /** الصنف النافد يظهر بلا أي إعداد مسبق — الحدّ الافتراضي صفر. */
    public function test_out_of_stock_items_appear_without_any_configuration(): void
    {
        $this->stock(0);
        $this->stock(5);

        $rows = $this->service()->lowStock($this->warehouse());

        $this->assertCount(1, $rows);
        $this->assertSame(0.0, (float) $rows->first()->on_hand);
        $this->assertSame(1, $this->service()->lowStockCount($this->warehouse()));
    }

    /** رفع الحدّ الافتراضي من الإعدادات يوسّع التنبيه فورًا. */
    public function test_raising_the_default_threshold_widens_the_alert(): void
    {
        $this->stock(3);
        $this->stock(9);

        $this->assertCount(0, $this->service()->lowStock($this->warehouse()));

        Settings::set('inventory.default_reorder_level', 5, 'inventory', 'number');

        $this->assertCount(1, $this->service()->lowStock($this->warehouse()));
    }

    /** حدّ المنتج يتقدّم على الافتراضي. */
    public function test_product_level_threshold_overrides_the_default(): void
    {
        $this->stock(4, ['product' => ['reorder_level' => 10]]);
        $this->stock(4);

        $rows = $this->service()->lowStock($this->warehouse());

        $this->assertCount(1, $rows, 'الصنف ذو الحدّ الخاص فقط يجب أن يظهر.');
        $this->assertSame(10.0, (float) $rows->first()->effective_reorder_level);
    }

    /** حدّ سطر المخزون يتقدّم على حدّ المنتج والمتغيّر. */
    public function test_stock_row_threshold_wins_over_product_and_variant(): void
    {
        $this->stock(8, ['product' => ['reorder_level' => 2], 'variant_level' => 3, 'stock_level' => 20]);

        $rows = $this->service()->lowStock($this->warehouse());

        $this->assertCount(1, $rows);
        $this->assertSame(20.0, (float) $rows->first()->effective_reorder_level);
    }

    /** مؤشّر اللوحة يطابق عدد صفوف الصفحة — لا رقمان لنفس الشيء. */
    public function test_dashboard_count_matches_the_page(): void
    {
        $this->stock(0);
        $this->stock(0);
        $this->stock(7);

        $this->assertSame(
            $this->service()->lowStock($this->warehouse())->count(),
            $this->service()->dashboard($this->warehouse())['low_stock'],
        );
    }

    public function test_page_lists_the_items(): void
    {
        $this->stock(0);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');

        $rows = $this->actingAs($admin)
            ->get(route('admin.inventory.low_stock'))
            ->assertOk()
            ->viewData('rows');

        $this->assertCount(1, $rows);
    }
}
