<?php

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Services\CartService;
use App\Modules\Store\Services\CheckoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutDeliveryFeeTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function placeOrder(): Order
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 20, 60);

        $carts = app(CartService::class);
        $cart = Cart::create(['session_token' => 'sess-'.uniqid(), 'branch_id' => Branch::default()->id, 'status' => 'active']);
        $carts->addItem($cart, $variant->fresh(), 1);

        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart->fresh('items'));
        $session->update([
            'customer_name' => 'زبون', 'customer_phone' => '966500000000',
            'shipping_address' => ['line1' => 'x', 'city' => 'y'], 'payment_method_code' => 'cod',
        ]);

        return $checkout->place($session->fresh());
    }

    public function test_no_delivery_fee_by_default(): void
    {
        $order = $this->placeOrder();
        $this->assertEqualsWithDelta(0.0, (float) $order->shipping_total, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.001);
    }

    public function test_applies_configured_delivery_fee(): void
    {
        Settings::set('delivery.default_fee', 15, 'delivery');
        $order = $this->placeOrder();
        $this->assertEqualsWithDelta(15.0, (float) $order->shipping_total, 0.001);
        $this->assertEqualsWithDelta(115.0, (float) $order->total, 0.001);
    }

    public function test_free_shipping_above_threshold(): void
    {
        Settings::set('delivery.default_fee', 15, 'delivery');
        Settings::set('delivery.free_threshold', 50, 'delivery');
        $order = $this->placeOrder(); // subtotal 100 >= 50 → مجّاني
        $this->assertEqualsWithDelta(0.0, (float) $order->shipping_total, 0.001);
    }
}
