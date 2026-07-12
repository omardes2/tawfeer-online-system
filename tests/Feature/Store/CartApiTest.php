<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartApiTest extends TestCase
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

    private function sellableVariant(float $retail = 40, ?float $promo = null, float $stock = 10): ProductVariant
    {
        $product = Product::factory()->create(['is_active' => true, 'visibility' => 'visible']);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $retail, 'promo_price' => $promo]);
        app(InventoryService::class)->receive($variant, $this->warehouse, $stock, 10);

        return $variant;
    }

    public function test_guest_cannot_access_cart(): void
    {
        $this->getJson('/api/v1/store/cart')->assertUnauthorized();
    }

    public function test_empty_cart_is_created_on_first_access(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/store/cart')->assertOk()
            ->assertJsonPath('data.subtotal', 0)
            ->assertJsonPath('data.item_count', 0);
    }

    public function test_add_item_prices_from_catalog_promo(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, 35);

        $res = $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 3])->assertOk();
        $res->assertJsonPath('data.item_count', 1);
        $res->assertJsonPath('data.items.0.unit_price', '35.00'); // promo يفوز
        $res->assertJsonPath('data.subtotal', 105);
    }

    public function test_add_same_variant_accumulates(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 20);

        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 3]);
        $res = $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 2])->assertOk();
        $this->assertEquals('5.000', $res->json('data.items.0.qty'));
        $this->assertEquals(200, $res->json('data.subtotal')); // 5*40
    }

    public function test_update_and_remove_item(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 20);
        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 5]);

        $this->patchJson("/api/v1/store/cart/items/{$v->uuid}", ['qty' => 2])
            ->assertOk()->assertJsonPath('data.subtotal', 80);

        // qty 0 يحذف البند.
        $this->patchJson("/api/v1/store/cart/items/{$v->uuid}", ['qty' => 0])
            ->assertOk()->assertJsonPath('data.item_count', 0);
    }

    public function test_clear_cart(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 2]);

        $this->deleteJson('/api/v1/store/cart')->assertOk()->assertJsonPath('data.item_count', 0);
    }

    public function test_cannot_add_beyond_available_stock(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 5);

        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 10])->assertUnprocessable();
    }

    public function test_cannot_add_non_sellable_product(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create(['is_active' => false, 'visibility' => 'visible']);
        $v = $product->defaultVariant;
        app(InventoryService::class)->receive($v, $this->warehouse, 10, 10);

        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 1])->assertUnprocessable();
    }

    public function test_cart_is_scoped_per_user(): void
    {
        $v = $this->sellableVariant(40, null, 20);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => 3]);

        $other = User::factory()->create(['branch_id' => Branch::default()->id]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/store/cart')->assertOk()->assertJsonPath('data.item_count', 0);
    }
}
