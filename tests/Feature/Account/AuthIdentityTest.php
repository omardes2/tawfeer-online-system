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
use App\Modules\Store\Models\SocialIdentity;
use App\Support\Contracts\SocialAuthProvider;
use App\Support\Social\SocialUserData;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Support\FakeSocialAuthProvider;
use Tests\TestCase;

class AuthIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        FakeSocialAuthProvider::$user = null;
        $this->app->instance(SocialAuthProvider::class, new FakeSocialAuthProvider);
    }

    private function socialUser(string $provider, string $id, ?string $email, string $name = 'Social User'): void
    {
        FakeSocialAuthProvider::$user = new SocialUserData($provider, $id, $email, $name, null);
    }

    private function customerUser(array $userAttrs = [], array $customerAttrs = []): array
    {
        $user = User::factory()->create(array_merge(['branch_id' => Branch::default()->id], $userAttrs));
        $user->assignRole('customer');
        $customer = Customer::factory()->create(array_merge(['user_id' => $user->id], $customerAttrs));

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

    // ---- التسجيل الاجتماعي ----

    public function test_google_registration_creates_account_identity_and_needs_completion(): void
    {
        $this->socialUser('google', 'g-123', 'g@example.com', 'Google Person');

        $this->get('/auth/google/callback')->assertRedirect(route('account.profile.complete'));

        $this->assertAuthenticated();
        $user = User::where('email', 'g@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertDatabaseHas('social_identities', ['provider' => 'google', 'provider_id' => 'g-123', 'user_id' => $user->id]);
        $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'source' => 'social:google']);
    }

    public function test_facebook_registration_creates_account(): void
    {
        $this->socialUser('facebook', 'fb-9', 'fb@example.com', 'FB Person');
        $this->get('/auth/facebook/callback')->assertRedirect(route('account.profile.complete'));

        $this->assertDatabaseHas('social_identities', ['provider' => 'facebook', 'provider_id' => 'fb-9']);
    }

    public function test_existing_email_links_provider_without_duplicate(): void
    {
        [$user] = $this->customerUser(['email' => 'exists@example.com']);
        $before = User::count();

        $this->socialUser('google', 'g-777', 'exists@example.com', 'Existing');
        $this->get('/auth/google/callback');

        $this->assertEquals($before, User::count()); // لا حساب مكرّر
        $this->assertDatabaseHas('social_identities', ['provider' => 'google', 'provider_id' => 'g-777', 'user_id' => $user->id]);
    }

    public function test_duplicate_prevention_same_identity_reused(): void
    {
        $this->socialUser('google', 'g-dup', 'dup@example.com', 'Dup');
        $this->get('/auth/google/callback');
        $this->post('/account/logout');

        $this->socialUser('google', 'g-dup', 'dup@example.com', 'Dup');
        $this->get('/auth/google/callback');

        $this->assertEquals(1, SocialIdentity::where('provider', 'google')->where('provider_id', 'g-dup')->count());
        $this->assertEquals(1, User::where('email', 'dup@example.com')->count());
    }

    public function test_social_guest_cart_merge(): void
    {
        $variant = $this->sellableVariant(40, 10);
        $token = (string) Str::uuid();
        $this->postJson('/api/v1/store/cart/items', ['variant' => $variant->uuid, 'qty' => 2], ['X-Cart-Token' => $token])->assertOk();

        $this->socialUser('google', 'g-merge', 'merge@example.com', 'Merger');
        $this->withCookie('cart_token', $token)->get('/auth/google/callback');

        $user = User::where('email', 'merge@example.com')->first();
        $cart = Cart::where('user_id', $user->id)->where('status', 'active')->first();
        $this->assertEquals(2, (float) $cart->items()->where('variant_id', $variant->id)->value('qty'));
    }

    // ---- إكمال الملف وحارس الشراء ----

    public function test_checkout_redirects_incomplete_profile(): void
    {
        [$user] = $this->customerUser([], ['primary_phone' => null, 'birth_date' => null, 'preferred_locale' => null, 'communication_preferences' => null]);
        $this->actingAs($user);

        $this->get('/checkout')->assertRedirect(route('account.profile.complete'));

        // إكمال الملف يفتح الشراء.
        $this->post(route('account.profile.complete.store'), [
            'phone' => '0500000000', 'birth_date' => '1990-01-01',
            'preferred_locale' => 'ar', 'communication_preferences' => ['email' => '1'],
        ])->assertRedirect();

        $this->get('/checkout')->assertOk();
    }

    // ---- ربط/فكّ الربط ----

    public function test_link_and_unlink_provider(): void
    {
        [$user] = $this->customerUser(['email' => 'link@example.com']);
        $this->actingAs($user);

        // ربط عبر callback بنيّة link.
        $this->socialUser('google', 'g-link', 'other@example.com', 'Link');
        $this->withSession(['social_intent' => 'link'])->get('/auth/google/callback')
            ->assertRedirect(route('account.profile'));
        $this->assertDatabaseHas('social_identities', ['provider' => 'google', 'provider_id' => 'g-link', 'user_id' => $user->id]);

        // عرض المزوّدين.
        $this->get('/account/providers')->assertOk()->assertSee(__('account.connected'));

        // فكّ الربط (لديه كلمة مرور/بريد حقيقي → مسموح).
        $this->delete(route('account.providers.unlink', 'google'))->assertRedirect();
        $this->assertDatabaseMissing('social_identities', ['provider' => 'google', 'provider_id' => 'g-link']);
    }

    public function test_cannot_unlink_last_provider_for_social_only_user(): void
    {
        // مستخدم اجتماعي فقط ببريد صناعي (لا استرجاع بكلمة مرور).
        $this->socialUser('google', 'g-only', null, 'Only');
        $this->get('/auth/google/callback');
        $user = User::where('email', 'like', 'google_%@social.local')->first();
        $this->assertNotNull($user);

        $this->actingAs($user)->delete(route('account.providers.unlink', 'google'))
            ->assertSessionHasErrors('provider');
        $this->assertEquals(1, $user->socialIdentities()->count());
    }

    // ---- كلمة المرور / الهاتف / الشروط ----

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        [$user] = $this->customerUser(['email' => 'reset@example.com']);

        $this->post('/account/forgot-password', ['email' => 'reset@example.com'])->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);
        $this->post('/account/reset-password', [
            'token' => $token, 'email' => 'reset@example.com',
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertRedirect(route('account.login'));

        $this->assertTrue(auth()->attempt(['email' => 'reset@example.com', 'password' => 'newpass123']));
    }

    public function test_phone_login(): void
    {
        [$user] = $this->customerUser(['phone' => '0512345678']);
        $user->update(['password' => 'secret123']);

        $this->post('/account/login', ['email' => '0512345678', 'password' => 'secret123'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        $payload = [
            'name' => 'x', 'email' => 'noterms@example.com', 'phone' => '0500000000',
            'birth_date' => '1990-01-01', 'password' => 'password123', 'password_confirmation' => 'password123',
        ];
        $this->post('/account/register', $payload)->assertSessionHasErrors('terms');

        $this->post('/account/register', array_merge($payload, ['terms' => '1']))->assertRedirect(route('account.dashboard'));
        $this->assertNotNull(User::where('email', 'noterms@example.com')->first()->terms_accepted_at);
    }

    public function test_guest_order_linked_on_registration_by_phone(): void
    {
        $variant = $this->sellableVariant(50, 20);
        // طلب ضيف بلا عميل، بهاتف معروف.
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'ضيف', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 50]], 2026);
        $this->assertNull($order->customer_id);

        $this->post('/account/register', [
            'name' => 'عميل', 'email' => 'guestorder@example.com', 'phone' => '0500000000',
            'birth_date' => '1990-01-01', 'password' => 'password123', 'password_confirmation' => 'password123', 'terms' => '1',
        ])->assertRedirect(route('account.dashboard'));

        $customer = Customer::where('user_id', User::where('email', 'guestorder@example.com')->value('id'))->first();
        $this->assertEquals($customer->id, $order->fresh()->customer_id);
    }
}
