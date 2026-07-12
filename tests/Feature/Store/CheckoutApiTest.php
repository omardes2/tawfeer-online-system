<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Store\Events\CheckoutCompleted;
use App\Modules\Store\Events\CheckoutStarted;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
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

    private function addToCart(ProductVariant $v, float $qty = 2): void
    {
        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => $qty])->assertOk();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'عميل تجريبي',
            'customer_phone' => '0500000000',
            'shipping_address' => 'الرياض - حي النخيل - شارع 1',
            'payment_method' => 'cod',
        ], $overrides);
    }

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson('/api/v1/store/checkout', $this->payload())->assertUnauthorized();
    }

    public function test_cannot_checkout_empty_cart(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/store/checkout', $this->payload())->assertUnprocessable();
    }

    public function test_successful_checkout_creates_order_and_payment(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 3);

        $this->postJson('/api/v1/store/checkout', $this->payload())->assertOk()
            ->assertJsonPath('data.status', 'stock_reserved')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.item_count', 1)
            ->assertJsonPath('data.total', '120.00')
            ->assertJsonPath('data.payment.status', 'pending');

        $this->assertDatabaseHas('orders', ['channel' => 'web', 'status' => 'stock_reserved']);
    }

    public function test_checkout_reserves_inventory(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 4);

        $this->postJson('/api/v1/store/checkout', $this->payload())->assertOk();

        $stock = InventoryStock::where('variant_id', $v->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(4, (float) $stock->reserved);
        $this->assertEquals(10, (float) $stock->on_hand);
    }

    public function test_checkout_converts_cart_and_starts_fresh(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 2);

        $this->postJson('/api/v1/store/checkout', $this->payload())->assertOk();

        // سلة جديدة فارغة عند الوصول التالي (السلة السابقة صارت converted).
        $this->getJson('/api/v1/store/cart')->assertOk()->assertJsonPath('data.item_count', 0);
    }

    public function test_inactive_payment_method_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        // hyperpay مسجّل لكنه غير مُفعّل (is_active=false).
        $this->postJson('/api/v1/store/checkout', $this->payload(['payment_method' => 'hyperpay']))
            ->assertUnprocessable();
    }

    public function test_unknown_payment_method_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $this->postJson('/api/v1/store/checkout', $this->payload(['payment_method' => 'nope']))
            ->assertUnprocessable();
    }

    public function test_checkout_rejected_when_stock_dropped_below_cart_qty(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 5);
        $this->addToCart($v, 5);

        // إخراج مخزون بعد الإضافة يخفّض المتاح دون كمية السلة.
        app(InventoryService::class)->issue($v, $this->warehouse, 3);

        $this->postJson('/api/v1/store/checkout', $this->payload())->assertUnprocessable();
    }

    public function test_checkout_dispatches_domain_events(): void
    {
        Event::fake([CheckoutStarted::class, CheckoutCompleted::class]);
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 2);

        $this->postJson('/api/v1/store/checkout', $this->payload())->assertOk();

        Event::assertDispatched(CheckoutStarted::class);
        Event::assertDispatched(CheckoutCompleted::class);
    }
}
