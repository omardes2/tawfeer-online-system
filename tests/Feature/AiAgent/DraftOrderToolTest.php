<?php

namespace Tests\Feature\AiAgent;

use App\Modules\AiAgent\Tools\CreateDraftOrderTool;
use App\Modules\AiAgent\Tools\ListDeliveryAreasTool;
use App\Modules\AiAgent\Tools\ToolRegistry;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إنشاء مسودّة طلبٍ من محادثة واتساب.
 *
 * **المسودّة تقف عند `draft` ولا تتجاوزها.** مكنسة الشحن المجدولة كل دقيقة
 * تلتقط أيّ طلبٍ حالته `confirmed` فما بعدها وله مدينة، فتُنشئ له طردًا
 * حقيقيًّا عند شركة التوصيل. فحدُّ الوكيل عند المسودّة ليس تحفّظًا بل هو ما
 * يمنع بضاعةً من الخروج إلى الشارع بلا قرار إنسان.
 */
class DraftOrderToolTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    private Product $product;

    private City $city;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $channel = MessagingChannel::create([
            'provider' => 'whatsapp', 'name' => 'رقم', 'external_id' => '1',
            'is_active' => true, 'ai_enabled' => true,
        ]);
        $contact = ChannelContact::create([
            'channel_id' => $channel->id, 'external_id' => '970599123456', 'last_inbound_at' => now(),
        ]);
        $this->conversation = Conversation::create([
            'channel_contact_id' => $contact->id,
            'status_id' => ConversationStatus::defaultId(),
            'last_message_at' => now(),
        ]);

        $this->product = Product::factory()->create(['name' => 'مكنسة', 'retail_price' => 100, 'wholesale_price' => 60]);
        $this->product->defaultVariant->update(['retail_price' => 100, 'wholesale_price' => 60]);

        $this->city = $this->city('رام الله');
        $this->area = Area::create(['city_id' => $this->city->id, 'name' => 'الماصيون', 'is_active' => true]);
        DeliveryCityRate::create(['city_id' => $this->city->id, 'name' => 'رام الله', 'delivery_fee' => 20, 'is_active' => true]);
    }

    private function city(string $name): City
    {
        $gov = Governorate::query()->firstOrCreate(['name' => 'محافظة تجريبية'], ['is_active' => true]);

        return City::create(['governorate_id' => $gov->id, 'name' => $name, 'is_active' => true]);
    }

    private function tool(): CreateDraftOrderTool
    {
        $tool = app(CreateDraftOrderTool::class);
        $tool->setConversation($this->conversation);

        return $tool;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => [['variant_id' => $this->product->defaultVariant->id, 'qty' => 2]],
            'customer_name' => 'أبو محمد',
            'city_id' => $this->city->id,
            'area_id' => $this->area->id,
            'shipping_address' => 'شارع الإرسال، قرب المسجد',
        ], $overrides);
    }

    /** الطلب يُنشأ مسودّةً بمصدر واتساب. */
    public function test_it_creates_a_whatsapp_draft(): void
    {
        $result = $this->tool()->handle($this->payload());

        $order = Order::where('number', $result['order_number'])->firstOrFail();

        $this->assertSame('draft', $order->status);
        $this->assertSame('whatsapp', $order->channel);
        $this->assertSame('أبو محمد', $order->customer_name);
    }

    /**
     * ولا يتجاوز `draft` أبدًا.
     *
     * الحالات التالية تلتقطها مكنسة الشحن فتُنشئ طردًا حقيقيًّا.
     */
    public function test_it_never_reaches_a_dispatchable_status(): void
    {
        $result = $this->tool()->handle($this->payload());
        $order = Order::where('number', $result['order_number'])->firstOrFail();

        $dispatchable = ['confirmed', 'stock_reserved', 'preparing', 'ready_to_ship',
            'shipped', 'out_for_delivery', 'delayed', 'customer_unavailable'];

        $this->assertNotContains($order->status, $dispatchable);
        $this->assertNull($order->tracking_number);
        $this->assertSame('draft', $result['status']);
    }

    /**
     * ورقم الزبون من جهة الاتصال لا من النموذج.
     *
     * رقمٌ يمليه النموذج قابل للاختلاق، وطلبٌ برقمٍ مخترع يصل إلى شخصٍ آخر.
     */
    public function test_the_phone_comes_from_the_contact_not_the_model(): void
    {
        $result = $this->tool()->handle($this->payload(['customer_phone' => '0599999999']));

        $order = Order::where('number', $result['order_number'])->firstOrFail();

        $this->assertSame('970599123456', $order->customer_phone);
    }

    /** ورسوم التوصيل من الخلفية لا من النموذج. */
    public function test_the_delivery_fee_comes_from_the_backend(): void
    {
        $result = $this->tool()->handle($this->payload());

        $this->assertSame('20.00', $result['shipping_total']);
        $this->assertSame('220.00', $result['total']);   // 2 × 100 + 20
    }

    /** ومحادثةٌ بلا جهة اتصال لا تُنشئ طلبًا. */
    public function test_it_refuses_without_a_contact_phone(): void
    {
        $tool = app(CreateDraftOrderTool::class);   // بلا محادثة

        $result = $tool->handle($this->payload());

        $this->assertSame('no_contact', $result['error']);
        $this->assertSame(0, Order::count());
    }

    /** والصنف المجهول يُعاد كخطأ لا كطلبٍ ناقص. */
    public function test_an_unknown_variant_is_rejected(): void
    {
        $result = $this->tool()->handle($this->payload(['items' => [['variant_id' => 999999, 'qty' => 1]]]));

        $this->assertSame('unknown_variant', $result['error']);
        $this->assertSame(0, Order::count());
    }

    /**
     * وحارس البيع بأقل من الجملة يبقى قائمًا.
     *
     * الوكيل يمرّ بـ`OrderService` نفسه، فلا يملك طريقًا يلتفّ به على قواعد
     * البيع.
     */
    public function test_it_still_obeys_the_wholesale_floor(): void
    {
        // سعر الجملة أعلى من سعر البيع ⇒ الحارس يرفض.
        $this->product->defaultVariant->update(['wholesale_price' => 500]);

        $result = $this->tool()->handle($this->payload());

        $this->assertSame('rejected', $result['error']);
        $this->assertSame(0, Order::count());
    }

    /** والمحادثة تُربَط بالطلب فتُعرف من الصندوق. */
    public function test_the_conversation_is_linked_to_the_order(): void
    {
        $result = $this->tool()->handle($this->payload());

        $order = Order::where('number', $result['order_number'])->firstOrFail();

        $this->assertSame($order->id, $this->conversation->fresh()->order_id);
    }

    // ────────── المدن والمناطق ──────────

    /** المدن تُقرأ من النظام لا من قائمةٍ في الكود. */
    public function test_it_lists_cities_from_the_system(): void
    {
        $result = app(ListDeliveryAreasTool::class)->handle([]);

        $this->assertContains('رام الله', array_column($result['cities'], 'name'));
    }

    /** ومناطق المدينة تُقرأ لها وحدها. */
    public function test_it_lists_the_areas_of_one_city(): void
    {
        $other = $this->city('نابلس');
        Area::create(['city_id' => $other->id, 'name' => 'رفيديا', 'is_active' => true]);

        $result = app(ListDeliveryAreasTool::class)->handle(['city_id' => $this->city->id]);

        $names = array_column($result['areas'], 'name');

        $this->assertContains('الماصيون', $names);
        $this->assertNotContains('رفيديا', $names);
    }

    /** والأداتان مسجَّلتان فيراهما النموذج. */
    public function test_both_tools_are_registered(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->has('create_draft_order'));
        $this->assertTrue($registry->has('list_delivery_areas'));
    }

    /** والسجلّ يمرّر المحادثة إلى الأداة التي تحتاجها. */
    public function test_the_registry_passes_the_conversation_through(): void
    {
        $result = app(ToolRegistry::class)
            ->forConversation($this->conversation)
            ->call('create_draft_order', $this->payload());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('draft', $result['status']);
    }
}
