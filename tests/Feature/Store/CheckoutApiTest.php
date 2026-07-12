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
use Illuminate\Support\Str;
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

    /** @param array<string, string> $headers */
    private function addToCart(ProductVariant $v, float $qty = 2, array $headers = []): void
    {
        $this->postJson('/api/v1/store/cart/items', ['variant' => $v->uuid, 'qty' => $qty], $headers)->assertOk();
    }

    /** @return array<string, mixed> */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'عميل تجريبي',
            'customer_phone' => '0500000000',
            'shipping_address' => 'الرياض - حي النخيل - شارع 1',
            'payment_method_code' => 'cod',
        ], $overrides);
    }

    // ---- المصادقة والهوية ----

    public function test_guest_without_token_cannot_start_checkout(): void
    {
        $this->postJson('/api/v1/store/checkout')->assertUnauthorized();
    }

    public function test_cannot_start_checkout_with_empty_cart(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/store/checkout')->assertUnprocessable();
    }

    // ---- التدفّق متعدّد الخطوات (مستخدم مُصادَق) ----

    public function test_successful_multistep_checkout(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 3);

        $sid = $this->postJson('/api/v1/store/checkout')->assertSuccessful()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.ready', false)
            ->json('data.id');

        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details())->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.payment_method', 'cod');

        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk()
            ->assertJsonPath('data.status', 'stock_reserved')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.item_count', 1)
            ->assertJsonPath('data.total', '120.00')
            ->assertJsonPath('data.payment.status', 'pending');

        $this->assertDatabaseHas('orders', ['channel' => 'web', 'status' => 'stock_reserved']);
        $this->assertDatabaseHas('checkout_sessions', ['uuid' => $sid, 'status' => 'placed']);
    }

    public function test_checkout_reserves_inventory(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 4);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk();

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

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk();

        $this->getJson('/api/v1/store/cart')->assertOk()->assertJsonPath('data.item_count', 0);
    }

    public function test_place_rejected_when_details_incomplete(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        // لم تُضبط بيانات الشحن/الدفع.
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertUnprocessable();
    }

    public function test_inactive_payment_method_rejected_at_place(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        // hyperpay مسجّل (يمرّ exists) لكنه غير مُفعّل → يُرفض عند الإتمام.
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details(['payment_method_code' => 'hyperpay']))->assertOk();
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertUnprocessable();
    }

    public function test_unknown_payment_method_rejected_at_update(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details(['payment_method_code' => 'nope']))
            ->assertUnprocessable();
    }

    public function test_place_rejected_when_stock_dropped_below_cart_qty(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 5);
        $this->addToCart($v, 5);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());

        // إخراج مخزون بعد بدء الجلسة يخفّض المتاح دون كمية السلة.
        app(InventoryService::class)->issue($v, $this->warehouse, 3);

        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertUnprocessable();
    }

    public function test_checkout_dispatches_domain_events(): void
    {
        Event::fake([CheckoutStarted::class, CheckoutCompleted::class]);
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant(40, null, 10);
        $this->addToCart($v, 2);

        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');
        Event::assertDispatched(CheckoutStarted::class);

        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details());
        $this->postJson("/api/v1/store/checkout/{$sid}/place")->assertOk();
        Event::assertDispatched(CheckoutCompleted::class);
    }

    public function test_cannot_access_another_users_session(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->sellableVariant();
        $this->addToCart($v, 1);
        $sid = $this->postJson('/api/v1/store/checkout')->json('data.id');

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/store/checkout/{$sid}")->assertForbidden();
    }

    // ---- إتمام الضيف (رمز سلة) ----

    public function test_guest_checkout_end_to_end(): void
    {
        $token = (string) Str::uuid();
        $headers = ['X-Cart-Token' => $token];
        $v = $this->sellableVariant(50, null, 10);

        $this->addToCart($v, 2, $headers);

        $sid = $this->postJson('/api/v1/store/checkout', [], $headers)->assertSuccessful()->json('data.id');
        $this->patchJson("/api/v1/store/checkout/{$sid}", $this->details(), $headers)->assertOk();
        $this->postJson("/api/v1/store/checkout/{$sid}/place", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'stock_reserved')
            ->assertJsonPath('data.total', '100.00');

        // طلب ضيف: بلا عميل مرتبط.
        $this->assertDatabaseHas('orders', ['channel' => 'web', 'customer_id' => null, 'status' => 'stock_reserved']);
    }

    public function test_guest_cannot_access_session_without_matching_token(): void
    {
        $token = (string) Str::uuid();
        $v = $this->sellableVariant();
        $this->addToCart($v, 1, ['X-Cart-Token' => $token]);
        $sid = $this->postJson('/api/v1/store/checkout', [], ['X-Cart-Token' => $token])->json('data.id');

        // رمز مختلف → ممنوع.
        $this->getJson("/api/v1/store/checkout/{$sid}", ['X-Cart-Token' => (string) Str::uuid()])
            ->assertForbidden();
    }
}
