<?php

namespace Tests\Feature\AiAgent;

use App\Modules\AiAgent\Models\AgentRun;
use App\Modules\AiAgent\Models\AgentToolCall;
use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\AiAgent\Tools\ToolRegistry;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOffer;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\OfferPricing;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Messaging\Models\ChannelContact;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Store\Services\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أدوات الوكيل.
 *
 * أثقل ما يُحرَس هنا هو المبدأ الأول: **السعر الذي تقوله الأداة هو السعر الذي
 * تقبله شاشة الدفع**. ولو حسبت الأداة سعرًا مستقلًّا لقال الوكيل رقمًا يجده
 * الزبون مختلفًا في السلة — وهو أسرع طريقٍ إلى فقد الثقة.
 */
class AgentToolsTest extends TestCase
{
    use RefreshDatabase;

    private ToolRegistry $tools;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->tools = app(ToolRegistry::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function product(string $name = 'مكنسة كليكي', float $price = 137.35, bool $ready = true): Product
    {
        $product = Product::factory()->active()->create([
            'name' => $name,
            'visibility' => 'visible',
            'retail_price' => $price,
        ]);
        $product->defaultVariant->update(['retail_price' => $price, 'average_cost' => 40]);

        ProductKnowledge::create([
            'product_id' => $product->id,
            'selling_points' => ['يوفّر وقت التنظيف'],
            'objections' => [['objection' => 'غالي', 'response' => 'بيوفّر أجرة عاملة']],
            'faq' => [['question' => 'بطارية؟', 'answer' => 'إي، بطارية']],
            'is_ready' => $ready,
        ]);

        return $product->fresh('defaultVariant');
    }

    private function stock(ProductVariant $variant, float $qty): void
    {
        app(InventoryService::class)->receive($variant, $this->warehouse, $qty, 40);
    }

    // ────────── السعر ──────────

    /**
     * سعر الأداة هو سعر السلة نفسه — لا حسابٌ موازٍ.
     *
     * ويُختبَر بسعرٍ غير شائع (137.35) لا بمئةٍ مستديرة: الرقم المستدير يمرّ
     * صدفةً في حساباتٍ خاطئة كثيرة.
     */
    public function test_the_tool_price_equals_the_cart_price(): void
    {
        $product = $this->product(price: 137.35);
        $variant = $product->defaultVariant;

        $result = $this->tools->call('get_price', ['variant_id' => $variant->id, 'qty' => 1]);

        $this->assertSame('137.35', $result['unit_price']);
        $this->assertSame(
            number_format(app(CartService::class)->sellingPrice($variant), 2, '.', ''),
            $result['unit_price'],
        );
    }

    /** والعرض الكمّي يُطبَّق كما يُطبَّق في السلة تمامًا. */
    public function test_a_quantity_offer_is_applied_exactly_as_the_cart_does(): void
    {
        $product = $this->product(price: 40);
        ProductOffer::create([
            'product_id' => $product->id,
            'min_qty' => 3,
            'total_price' => 100,
            'is_active' => true,
        ]);

        $variant = $product->defaultVariant;
        $result = $this->tools->call('get_price', ['variant_id' => $variant->id, 'qty' => 3]);

        // ٣ بـ١٠٠ لا ٣×٤٠: لولا التفويض لقال الوكيل ١٢٠ ووجد الزبون أقلّ.
        $this->assertSame('20.01', $result['discount']);

        /*
         * والمطابقة مع السلة تُقاس بحسابها هي لا برقمٍ مكتوب هنا: السلة تحفظ
         * سعر القطعة (33.33) وتضربه في الكمّية، فتُحصّل 99.99 على عرضِ مئة.
         * فلو قال الوكيل «مئة» لوجد الزبون غيرها في الدفع — وهو بالضبط ما
         * تمنعه هذه الأداة. (الفارق قرشٌ مقصودٌ في تسعير السلة، وذِكرُه هنا
         * توثيقٌ لا إقرارٌ بصحّته.)
         */
        $cartUnit = app(OfferPricing::class)->unitPrice(
            $product->activeOffers,
            3,
            app(CartService::class)->sellingPrice($variant),
        );

        $this->assertSame(number_format($cartUnit * 3, 2, '.', ''), $result['total']);
    }

    /** والمبالغ نصوصٌ لا أعدادًا عائمة — النموذج يُعيد كتابة ما يُعطى. */
    public function test_money_reaches_the_model_as_strings(): void
    {
        $result = $this->tools->call('get_price', [
            'variant_id' => $this->product()->defaultVariant->id, 'qty' => 1,
        ]);

        foreach (['unit_price', 'total', 'discount'] as $key) {
            $this->assertIsString($result[$key]);
        }
    }

    // ────────── التوفّر ──────────

    /**
     * المتاح = الموجود ناقص المحجوز.
     *
     * وقولُ «متوفّر» عن قطعةٍ محجوزة لطلبٍ آخر يُنتج طلبًا لا يمكن تنفيذه
     * واعتذارًا لزبونٍ وُعد.
     */
    public function test_reserved_stock_is_not_available(): void
    {
        $product = $this->product();
        $variant = $product->defaultVariant;
        $this->stock($variant, 5);

        $variant->inventoryStocks()->update(['reserved' => 5]);

        $result = $this->tools->call('check_stock', ['variant_id' => $variant->id]);

        $this->assertFalse($result['is_available']);
    }

    // ────────── المعرفة البيعية ──────────

    /** الصنف غير الجاهز لا يظهر في البحث أصلًا. */
    public function test_an_unready_product_is_never_searchable(): void
    {
        $this->product('صنف بلا معرفة', ready: false);

        $result = $this->tools->call('search_products', ['query' => 'صنف']);

        $this->assertSame([], $result['products']);
    }

    /** وتفاصيله تقول صراحةً «حوّل» بدل أن ترتجل. */
    public function test_unready_details_order_a_handoff(): void
    {
        $product = $this->product('صنف بلا معرفة', ready: false);

        $result = $this->tools->call('get_product_details', ['product_id' => $product->id]);

        $this->assertFalse($result['is_ready']);
        $this->assertStringContainsString('موظفة', $result['message']);
        $this->assertArrayNotHasKey('selling_points', $result);
    }

    /** والجاهز يعطي الاعتراضات المكتوبة لا ارتجالًا. */
    public function test_ready_details_carry_the_written_objections(): void
    {
        $product = $this->product();

        $result = $this->tools->call('get_product_details', ['product_id' => $product->id]);

        $this->assertTrue($result['is_ready']);
        $this->assertSame('غالي', $result['objections'][0]['objection']);
    }

    // ────────── لا تسريب ──────────

    /**
     * لا تصل التكلفة إلى النموذج.
     *
     * تمريرُ عمودٍ زائد يُغري النموذج بذكره — وتكلفة الشراء آخر ما يجوز أن
     * يقوله بائعٌ لزبون.
     */
    public function test_purchase_cost_never_reaches_the_model(): void
    {
        $product = $this->product();

        $encoded = json_encode([
            $this->tools->call('search_products', ['query' => 'مكنسة']),
            $this->tools->call('get_product_details', ['product_id' => $product->id]),
            $this->tools->call('get_price', ['variant_id' => $product->defaultVariant->id, 'qty' => 1]),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['average_cost', 'cost_price', 'wholesale', 'min_price'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $encoded);
        }
    }

    // ────────── السجلّ ──────────

    /** كل استدعاءٍ يُسجَّل — وبه وحده يُعرَف أجاء السعر من أداةٍ أم اختُرع. */
    public function test_every_tool_call_is_recorded(): void
    {
        $run = AgentRun::create([
            'conversation_id' => $this->conversation()->id,
            'model' => 'claude-sonnet-5',
            'outcome' => 'replied',
        ]);

        $this->tools->call('get_price', ['variant_id' => $this->product()->defaultVariant->id, 'qty' => 2], $run);

        $call = AgentToolCall::firstOrFail();

        $this->assertSame('get_price', $call->tool_name);
        $this->assertSame('ok', $call->status);
        $this->assertSame(2, $call->arguments['qty']);
    }

    /** والفشل يُسجَّل ويُعاد إلى النموذج بدل أن يُسقط الدورة. */
    public function test_a_failing_tool_returns_an_error_instead_of_throwing(): void
    {
        $run = AgentRun::create([
            'conversation_id' => $this->conversation()->id,
            'model' => 'claude-sonnet-5',
            'outcome' => 'replied',
        ]);

        $result = $this->tools->call('get_price', ['variant_id' => 999999, 'qty' => 1], $run);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(1, AgentToolCall::count());
    }

    /** وأداةٌ لا يعرفها السجلّ لا تُنفَّذ. */
    public function test_an_unknown_tool_is_refused(): void
    {
        $result = $this->tools->call('delete_everything', []);

        $this->assertSame('unknown_tool', $result['error']);
    }

    /** والسجلّ لا يعرض للنموذج إلّا أدوات القراءة في هذه المرحلة. */
    public function test_only_read_tools_are_exposed(): void
    {
        $names = array_column($this->tools->definitions(), 'name');

        sort($names);

        $this->assertSame(
            ['check_stock', 'get_price', 'get_product_details', 'search_products'],
            $names,
        );
    }

    private function conversation(): Conversation
    {
        $channel = MessagingChannel::create([
            'provider' => 'whatsapp', 'name' => 'رقم', 'external_id' => '1',
            'is_active' => true, 'ai_enabled' => true,
        ]);

        $contact = ChannelContact::create([
            'channel_id' => $channel->id, 'external_id' => '970599123456', 'last_inbound_at' => now(),
        ]);

        return Conversation::create([
            'channel_contact_id' => $contact->id,
            'status_id' => ConversationStatus::defaultId(),
            'last_message_at' => now(),
        ]);
    }
}
