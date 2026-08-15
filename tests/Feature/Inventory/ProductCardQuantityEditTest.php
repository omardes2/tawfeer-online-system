<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تعديل الكميات من كرت الصنف.
 *
 * لا كتابة مباشرة على الرصيد: يُحسب الفرق ويُسجَّل حركة تسوية بسبب. وبلا تكلفة
 * عمدًا — التسوية بلا تكلفة لا تعيد حساب متوسط التكلفة، فتصحيحُ عددٍ لا يحرّك
 * أساس التكلفة ولا أرباح المبيعات اللاحقة.
 */
class ProductCardQuantityEditTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('admin');

        return $user;
    }

    /** صنف برصيد ابتدائي وتكلفة معروفة. */
    private function stocked(float $qty, float $unitCost = 50): Product
    {
        $product = Product::factory()->active()->create();
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, $qty, $unitCost);

        return $product->fresh();
    }

    private function submit(User $user, Product $product, array $quantities, ?string $reason = null): TestResponse
    {
        return $this->actingAs($user)->put(
            route('admin.inventory.products.quantities', $product),
            ['warehouse_id' => $this->warehouse->id, 'quantities' => $quantities, 'reason' => $reason],
        );
    }

    private function onHand(ProductVariant $variant): float
    {
        return (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand');
    }

    public function test_raising_the_quantity_records_an_adjustment_in(): void
    {
        $product = $this->stocked(10);
        $variant = $product->defaultVariant;

        $this->submit($this->manager(), $product, [$variant->id => 17], 'جرد')->assertRedirect();

        $this->assertEqualsWithDelta(17, $this->onHand($variant), 0.001);

        $movement = InventoryMovement::where('variant_id', $variant->id)->latest('id')->first();
        $this->assertSame('adjustment_in', $movement->type);
        $this->assertEqualsWithDelta(7, (float) $movement->qty, 0.001);   // الفرق لا الرقم المكتوب
        $this->assertStringContainsString('جرد', (string) $movement->reason);
    }

    public function test_lowering_the_quantity_records_an_adjustment_out(): void
    {
        $product = $this->stocked(10);
        $variant = $product->defaultVariant;

        $this->submit($this->manager(), $product, [$variant->id => 4])->assertRedirect();

        $this->assertEqualsWithDelta(4, $this->onHand($variant), 0.001);
        $this->assertSame('adjustment_out', InventoryMovement::where('variant_id', $variant->id)->latest('id')->first()->type);
    }

    public function test_the_average_cost_is_left_untouched(): void
    {
        // جوهر الأمان: تصحيح عددٍ لا يحرّك أساس التكلفة، فلا ينحرف ربح ما يُباع بعده.
        $product = $this->stocked(10, unitCost: 50);
        $variant = $product->defaultVariant;
        $before = (float) $variant->fresh()->average_cost;

        $this->submit($this->manager(), $product, [$variant->id => 100])->assertRedirect();

        $this->assertEqualsWithDelta(50, $before, 0.01);
        $this->assertEqualsWithDelta($before, (float) $variant->fresh()->average_cost, 0.01);
    }

    public function test_an_empty_box_means_no_change(): void
    {
        $product = $this->stocked(10);
        $variant = $product->defaultVariant;

        $this->submit($this->manager(), $product, [$variant->id => null])
            ->assertRedirect()->assertSessionHas('warning');

        $this->assertEqualsWithDelta(10, $this->onHand($variant), 0.001);
    }

    public function test_the_same_quantity_writes_no_movement(): void
    {
        $product = $this->stocked(10);
        $variant = $product->defaultVariant;
        $count = InventoryMovement::where('variant_id', $variant->id)->count();

        $this->submit($this->manager(), $product, [$variant->id => 10])->assertRedirect();

        $this->assertSame($count, InventoryMovement::where('variant_id', $variant->id)->count());
    }

    public function test_a_negative_quantity_is_refused(): void
    {
        $product = $this->stocked(10);

        $this->submit($this->manager(), $product, [$product->defaultVariant->id => -5])
            ->assertSessionHasErrors('quantities.'.$product->defaultVariant->id);
    }

    public function test_a_user_without_stock_permission_is_refused(): void
    {
        $product = $this->stocked(10);
        // المحاسب يرى الكتالوج ولا يحرّك المخزون.
        $accountant = User::factory()->create(['branch_id' => Branch::default()->id]);
        $accountant->assignRole('accountant');

        $this->submit($accountant, $product, [$product->defaultVariant->id => 99])->assertForbidden();
        $this->assertEqualsWithDelta(10, $this->onHand($product->defaultVariant), 0.001);
    }

    public function test_a_variant_of_another_product_is_ignored(): void
    {
        $mine = $this->stocked(10);
        $other = $this->stocked(10);

        $this->submit($this->manager(), $mine, [$other->defaultVariant->id => 999])->assertRedirect();

        // لا يُعدَّل صنفٌ آخر بتمرير معرّفه في النموذج.
        $this->assertEqualsWithDelta(10, $this->onHand($other->defaultVariant), 0.001);
    }

    public function test_the_card_renders_a_row_per_variant(): void
    {
        $product = $this->stocked(10);

        $this->actingAs($this->manager())
            ->get(route('admin.inventory.products.edit', $product))
            ->assertOk()
            ->assertSee(__('الكميات'), false)
            ->assertSee('quantities['.$product->defaultVariant->id.']', false);
    }
}
