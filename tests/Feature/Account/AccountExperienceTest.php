<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Notifications\AccountWelcomeNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountExperienceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    /** @return array{0: User, 1: Customer} */
    private function customer(): array
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('customer');
        $customer = Customer::factory()->create(['user_id' => $user->id, 'name' => $user->name]);

        return [$user, $customer];
    }

    private function sellableVariant(float $price = 50, float $stock = 10): ProductVariant
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, $stock, $price);

        return $variant;
    }

    private function registerPayload(array $o = []): array
    {
        return array_merge([
            'name' => 'عميل جديد',
            'email' => 'new'.Str::random(5).'@example.com',
            'phone' => '0500000000',
            'birth_date' => '1990-05-20',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $o);
    }

    // ---- المصادقة ----

    public function test_guest_is_redirected_from_account(): void
    {
        $this->get('/account')->assertRedirect(route('account.login'));
    }

    public function test_registration_creates_user_and_customer_with_birth_date(): void
    {
        Notification::fake();
        $payload = $this->registerPayload(['email' => 'omar@example.com']);

        $this->post('/account/register', $payload)->assertRedirect(route('account.dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'omar@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('customer'));
        $customer = Customer::where('user_id', $user->id)->first();
        $this->assertNotNull($customer);
        $this->assertEquals('1990-05-20', $customer->birth_date->format('Y-m-d'));
        Notification::assertSentTo($user, AccountWelcomeNotification::class);
    }

    public function test_registration_requires_birth_date(): void
    {
        $this->post('/account/register', $this->registerPayload(['birth_date' => '']))
            ->assertSessionHasErrors('birth_date');
    }

    public function test_registration_merges_guest_cart(): void
    {
        $variant = $this->sellableVariant(40, 10);
        $token = (string) Str::uuid();

        // ضيف يضيف للسلة برمز.
        $this->postJson('/api/v1/store/cart/items', ['variant' => $variant->uuid, 'qty' => 2], ['X-Cart-Token' => $token])->assertOk();

        // التسجيل مع كوكي رمز السلة يدمجها.
        $this->withCookie('cart_token', $token)
            ->post('/account/register', $this->registerPayload(['email' => 'merge@example.com']))
            ->assertRedirect(route('account.dashboard'));

        $user = User::where('email', 'merge@example.com')->first();
        $userCart = Cart::where('user_id', $user->id)->where('status', 'active')->first();
        $this->assertNotNull($userCart);
        $this->assertEquals(2, (float) $userCart->items()->where('variant_id', $variant->id)->value('qty'));
        $this->assertEquals('merged', Cart::where('session_token', $token)->value('status'));
    }

    public function test_login_and_logout(): void
    {
        [$user] = $this->customer();
        $user->update(['password' => 'secret123']);

        $this->post('/account/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/account/logout')->assertRedirect(route('storefront.home'));
        $this->assertGuest();
    }

    // ---- الطلبات ----

    public function test_my_orders_scoped_and_tracking(): void
    {
        [$user, $customer] = $this->customer();
        $variant = $this->sellableVariant();
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => $customer->id, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 50]], 2026);

        // طلب لعميل آخر.
        [$other, $otherCustomer] = $this->customer();
        $otherOrder = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => $otherCustomer->id, 'customer_name' => 'y', 'customer_phone' => '0511111111',
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 50]], 2026);

        $this->actingAs($user);
        $this->get('/account/orders')->assertOk()->assertSee($order->number)->assertDontSee($otherOrder->number);
        $this->get(route('account.orders.show', $order))->assertOk()->assertSee($order->number);
        $this->get(route('account.orders.show', $otherOrder))->assertForbidden();
        $this->getJson(route('account.orders.status', $order))->assertOk()->assertJsonPath('status', $order->status);
    }

    public function test_reorder_adds_items_to_cart(): void
    {
        [$user, $customer] = $this->customer();
        $variant = $this->sellableVariant(50, 20);
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => $customer->id, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->id, 'qty' => 3, 'unit_price' => 50]], 2026);

        $this->actingAs($user);
        $this->post(route('account.orders.reorder', $order))->assertRedirect(route('storefront.cart'));

        $cart = Cart::where('user_id', $user->id)->where('status', 'active')->first();
        $this->assertEquals(3, (float) $cart->items()->where('variant_id', $variant->id)->value('qty'));
    }

    // ---- العناوين ----

    public function test_address_crud_and_single_default(): void
    {
        [$user, $customer] = $this->customer();
        $this->actingAs($user);

        $this->post('/account/addresses', ['address_line' => 'الرياض 1'])->assertRedirect();
        $this->post('/account/addresses', ['address_line' => 'جدة 2', 'is_default' => 1])->assertRedirect();

        $this->assertEquals(2, $customer->addresses()->count());
        // آخر عنوان افتراضي هو الوحيد الافتراضي.
        $this->assertEquals(1, $customer->addresses()->where('is_default', true)->count());
        $default = $customer->addresses()->where('is_default', true)->first();
        $this->assertEquals('جدة 2', $default->address_line);
    }

    // ---- المفضّلة ----

    public function test_wishlist_toggle_and_index(): void
    {
        [$user, $customer] = $this->customer();
        $product = $this->sellableVariant()->product;
        $this->actingAs($user);

        $this->post(route('account.wishlist.toggle', $product))->assertRedirect();
        $this->assertDatabaseHas('wishlist_items', ['customer_id' => $customer->id, 'product_id' => $product->id]);
        $this->get('/account/wishlist')->assertOk()->assertSee($product->slug);

        // التبديل يزيل.
        $this->post(route('account.wishlist.toggle', $product))->assertRedirect();
        $this->assertDatabaseMissing('wishlist_items', ['customer_id' => $customer->id, 'product_id' => $product->id]);
    }

    // ---- التفضيلات ----

    public function test_preferences_saved(): void
    {
        [$user, $customer] = $this->customer();
        $this->actingAs($user);

        $this->patch('/account/preferences', [
            'preferred_locale' => 'en',
            'preferred_branch_id' => Branch::default()->id,
            'communication_preferences' => ['whatsapp' => '1', 'email' => '1'],
        ])->assertRedirect();

        $customer->refresh();
        $this->assertEquals('en', $customer->preferred_locale);
        $this->assertTrue((bool) $customer->communication_preferences['whatsapp']);
    }

    // ---- الإشعارات ----

    public function test_notification_center(): void
    {
        [$user] = $this->customer();
        $user->notify(new AccountWelcomeNotification);
        $this->actingAs($user);

        $this->get('/account/notifications')->assertOk()->assertSee(__('account.welcome_title'));
        $this->assertEquals(1, $user->unreadNotifications()->count());

        $this->post(route('account.notifications.read_all'))->assertRedirect();
        $this->assertEquals(0, $user->fresh()->unreadNotifications()->count());
    }
}
