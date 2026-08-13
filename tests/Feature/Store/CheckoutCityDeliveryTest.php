<?php

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Models\CheckoutSession;
use App\Modules\Store\Services\CartService;
use App\Modules\Store\Services\CheckoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ربط إتمام شراء المتجر بمدن ومناطق النظام ورسوم التوصيل لكل مدينة.
 *
 * كان طلب الويب يصل بعنوان نصّي بلا مدينة، فيُحتسب برسم افتراضي واحد مهما كانت
 * الوجهة — بينما الطلب المُنشأ من اللوحة يحمل سعر مدينته. هذه الاختبارات تثبّت
 * أن المصدر صار واحدًا للاثنين.
 */
class CheckoutCityDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    /** مدينة مسعّرة + منطقة تابعة لها. */
    private function city(string $name, float $fee): City
    {
        $governorate = Governorate::firstOrCreate(
            ['name' => 'محافظة الاختبار'],
            ['country_code' => 'PS', 'is_active' => true],
        );
        $city = City::create(['governorate_id' => $governorate->id, 'name' => $name, 'is_active' => true]);
        DeliveryCityRate::create([
            'city_id' => $city->id, 'name' => $name,
            'delivery_fee' => $fee, 'currency' => 'ILS', 'is_active' => true,
        ]);

        return $city;
    }

    private function newSession(): CheckoutSession
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 20, 60);

        $cart = Cart::create([
            'session_token' => (string) Str::uuid(),
            'branch_id' => Branch::default()->id,
            'status' => 'active',
        ]);
        app(CartService::class)->addItem($cart, $variant->fresh(), 1);

        $session = app(CheckoutService::class)->start($cart->fresh('items'));
        $session->update([
            'customer_name' => 'زبون',
            'customer_phone' => '970599123456',
            'shipping_address' => 'شارع الإرسال',
            'payment_method_code' => 'cod',
        ]);

        return $session->fresh();
    }

    private function place(CheckoutSession $session): Order
    {
        return app(CheckoutService::class)->place($session);
    }

    public function test_order_carries_the_selected_city_and_area(): void
    {
        $city = $this->city('رام الله', 20);
        $area = Area::create(['city_id' => $city->id, 'name' => 'الماصيون', 'is_active' => true]);

        $session = $this->newSession();
        $session->update(['city_id' => $city->id, 'area_id' => $area->id]);

        $order = $this->place($session->fresh());

        $this->assertSame($city->id, $order->city_id);
        $this->assertSame($area->id, $order->area_id);
    }

    public function test_delivery_fee_follows_the_city_rate(): void
    {
        $ramallah = $this->city('رام الله', 20);
        $hebron = $this->city('الخليل', 35);

        $a = $this->newSession();
        $a->update(['city_id' => $ramallah->id]);
        $orderA = $this->place($a->fresh());

        $b = $this->newSession();
        $b->update(['city_id' => $hebron->id]);
        $orderB = $this->place($b->fresh());

        $this->assertEqualsWithDelta(20.0, (float) $orderA->shipping_total, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $orderA->total, 0.001);

        // نفس السلة، مدينة أغلى ⇒ إجمالي أعلى. هذا جوهر الربط.
        $this->assertEqualsWithDelta(35.0, (float) $orderB->shipping_total, 0.001);
        $this->assertEqualsWithDelta(135.0, (float) $orderB->total, 0.001);
    }

    public function test_city_rate_beats_the_default_settings_fee(): void
    {
        Settings::set('delivery.default_fee', 99, 'delivery');
        $city = $this->city('نابلس', 25);

        $session = $this->newSession();
        $session->update(['city_id' => $city->id]);

        $this->assertEqualsWithDelta(25.0, (float) $this->place($session->fresh())->shipping_total, 0.001);
    }

    public function test_free_shipping_threshold_still_wins(): void
    {
        Settings::set('delivery.free_threshold', 50, 'delivery');
        $city = $this->city('رام الله', 20);

        $session = $this->newSession();
        $session->update(['city_id' => $city->id]);

        // المجموع الفرعي 100 ≥ 50 ⇒ مجّاني رغم سعر المدينة.
        $this->assertEqualsWithDelta(0.0, (float) $this->place($session->fresh())->shipping_total, 0.001);
    }

    public function test_city_is_required_once_delivery_cities_are_configured(): void
    {
        $this->city('رام الله', 20);

        $this->expectException(ValidationException::class);
        $this->place($this->newSession());
    }

    public function test_store_without_configured_cities_still_sells(): void
    {
        // متجر لم تُضبط فيه مدن بعد لا يُمنع من البيع — السلوك السابق محفوظ.
        $order = $this->place($this->newSession());

        $this->assertNull($order->city_id);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.001);
    }

    public function test_api_rejects_an_area_from_another_city(): void
    {
        $ramallah = $this->city('رام الله', 20);
        $hebron = $this->city('الخليل', 35);
        $hebronArea = Area::create(['city_id' => $hebron->id, 'name' => 'وسط البلد', 'is_active' => true]);

        $session = $this->newSession();

        $this->withHeaders(['X-Cart-Token' => $session->cart->session_token])
            ->patchJson("/api/v1/store/checkout/{$session->uuid}", [
                'city_id' => $ramallah->id,
                'area_id' => $hebronArea->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('area_id');
    }

    public function test_api_rejects_a_city_without_an_active_rate(): void
    {
        $this->city('رام الله', 20);
        $unrated = City::create([
            'governorate_id' => Governorate::first()->id,
            'name' => 'مدينة بلا سعر',
            'is_active' => true,
        ]);

        $session = $this->newSession();

        $this->withHeaders(['X-Cart-Token' => $session->cart->session_token])
            ->patchJson("/api/v1/store/checkout/{$session->uuid}", ['city_id' => $unrated->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('city_id');
    }

    public function test_api_returns_the_backend_computed_totals(): void
    {
        $city = $this->city('رام الله', 20);
        $session = $this->newSession();

        $this->withHeaders(['X-Cart-Token' => $session->cart->session_token])
            ->patchJson("/api/v1/store/checkout/{$session->uuid}", ['city_id' => $city->id])
            ->assertOk()
            ->assertJsonPath('data.city_id', $city->id)
            ->assertJsonPath('data.cart.subtotal', 100)
            ->assertJsonPath('data.cart.delivery_fee', 20)
            ->assertJsonPath('data.cart.total', 120);
    }
}
