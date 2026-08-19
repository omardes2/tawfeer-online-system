<?php

namespace Tests\Feature\Marketing;

use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Models\CheckoutSession;
use App\Modules\Store\Services\CartService;
use App\Modules\Store\Services\CheckoutService;
use App\Support\Integrations\Messaging\FakeMessagingProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * المتابعة الآلية للطلبات غير المكتملة.
 *
 * الرسالة لا تُرسَل إلّا لمن له سجلّ عميل: حوكمة الموافقة والحجب تعمل على
 * العميل، ومراسلة رقمٍ لم يُوافق صاحبه مخالفةٌ لا اجتهاد فيها. ومن لا سجلّ له
 * يبقى لمكالمةٍ بشرية في شاشة «طلبات لم تكتمل».
 */
class AbandonedCheckoutCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['messaging.channels.whatsapp' => 'fake']);
        FakeMessagingProvider::reset();
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();

        Campaign::create([
            'name' => 'متابعة طلب لم يكتمل',
            'use_case' => 'abandoned_cart',
            'channel' => 'whatsapp',
            'status' => 'active',
            'trigger_type' => 'event',
            'trigger_event' => 'abandoned_checkout',
            'body_ar' => 'مرحبًا {{name}}، لم تُكمل طلبك.',
        ]);
    }

    private function abandoned(string $phone): CheckoutSession
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 20, 40);

        $cart = Cart::create([
            'session_token' => (string) Str::uuid(),
            'branch_id' => Branch::default()->id,
            'status' => 'active',
        ]);
        app(CartService::class)->addItem($cart, $variant->fresh(), 1);

        $session = app(CheckoutService::class)->start($cart->fresh('items'));
        $session->update(['customer_name' => 'زبون', 'customer_phone' => $phone]);
        $session->forceFill(['updated_at' => now()->subHours(2)])->save();

        return $session->fresh();
    }

    /** عميلٌ موافقٌ تصله الرسالة. */
    public function test_it_messages_a_known_opted_in_customer(): void
    {
        Customer::factory()->create([
            'primary_phone' => '0599123456',
            'communication_preferences' => ['whatsapp' => true],
        ]);
        $this->abandoned('0599123456');

        $this->artisan('marketing:run-abandoned-checkouts')->assertSuccessful();

        $this->assertSame(1, CampaignMessage::count());
        $this->assertCount(1, FakeMessagingProvider::$sent);
    }

    /** والضيف الذي لا سجلّ له لا تُرسَل إليه رسالة — يبقى للمكالمة. */
    public function test_a_guest_without_a_customer_record_is_never_messaged(): void
    {
        $this->abandoned('0599999999');

        $this->artisan('marketing:run-abandoned-checkouts')->assertSuccessful();

        $this->assertSame(0, CampaignMessage::count());
    }

    /** ولا تتكرّر الرسالة على الجلسة نفسها مهما أُعيد التشغيل. */
    public function test_the_same_checkout_is_never_messaged_twice(): void
    {
        Customer::factory()->create([
            'primary_phone' => '0599123456',
            'communication_preferences' => ['whatsapp' => true],
        ]);
        $this->abandoned('0599123456');

        $this->artisan('marketing:run-abandoned-checkouts')->assertSuccessful();
        $this->artisan('marketing:run-abandoned-checkouts')->assertSuccessful();

        $this->assertSame(1, CampaignMessage::count());
    }

    /** ومن سُجّلت عليه متابعة بشرية لا تُلاحقه رسالة آلية فوق المكالمة. */
    public function test_a_checkout_already_handled_by_a_human_is_skipped(): void
    {
        Customer::factory()->create([
            'primary_phone' => '0599123456',
            'communication_preferences' => ['whatsapp' => true],
        ]);
        $this->abandoned('0599123456')->update(['recovery_status' => 'contacted']);

        $this->artisan('marketing:run-abandoned-checkouts')->assertSuccessful();

        $this->assertSame(0, CampaignMessage::count());
    }
}
