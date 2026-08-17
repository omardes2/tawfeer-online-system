<?php

namespace Tests\Feature\Marketing;

use App\Http\Middleware\CaptureAdAttribution;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Marketing\Jobs\SendPurchaseConversion;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdExternalMap;
use App\Modules\Marketing\Services\AdAttributionService;
use App\Modules\Sales\Models\Order;
use App\Support\Integrations\Pixel\ConversionTrackerManager;
use App\Support\Integrations\Pixel\FakeConversionTracker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * نسبة الطلب الإلكتروني إلى الإعلان، وقياس الشراء.
 *
 * السؤال الذي تحرسه هذه الاختبارات: **أيّ إعلانٍ أنتج هذا الطلب؟** بلا جوابٍ
 * عنه لا حكم على حملة مبيعاتٍ ولا إيقاف لخاسرة — تصرف الحملة ويبدو كلّ شيء
 * سليمًا لأن لا أحد يعرف ما الذي لم يُنتج.
 *
 * وأخطر ما تحرسه: **ألّا يُنسَب طلبٌ لإعلانٍ لم يأتِ منه**. النسبة الخاطئة
 * أسوأ من غيابها، لأن غيابها ظاهرٌ في التقرير وخطأها ليس كذلك.
 */
class AdAttributionTest extends TestCase
{
    use RefreshDatabase;

    private AdChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config()->set('ads.pixel.driver', 'fake');
        FakeConversionTracker::reset();

        $this->channel = AdChannel::where('name', 'توفير اون لاين')->firstOrFail();
    }

    protected function tearDown(): void
    {
        FakeConversionTracker::reset();
        parent::tearDown();
    }

    /** ربط الحملة بالصفحة والمجموعة بالصنف — كما تفعله شاشة «ربط الحملات». */
    private function link(Product $product, string $adsetId = 'A1', string $campaignId = 'C1'): void
    {
        AdExternalMap::create([
            'provider' => 'meta', 'external_type' => AdExternalMap::TYPE_CAMPAIGN,
            'external_id' => $campaignId, 'ad_channel_id' => $this->channel->id,
        ]);

        AdExternalMap::create([
            'provider' => 'meta', 'external_type' => AdExternalMap::TYPE_ADSET,
            'external_id' => $adsetId, 'parent_external_id' => $campaignId,
            'product_id' => $product->id,
        ]);
    }

    private function product(): Product
    {
        return Product::factory()->create(['name' => 'مكنسة كليكي']);
    }

    // ────────── الالتقاط ──────────

    /** معاملات الإعلان تُلتقط من الزيارة وتُحفظ في كعكة. */
    public function test_it_captures_ad_parameters_from_the_landing_visit(): void
    {
        $response = $this->get(route('storefront.home', [
            'fbclid' => 'CLICK123',
            'utm_source' => 'facebook',
            'utm_campaign' => 'C1',
            'utm_content' => 'A1',
        ]))->assertOk();

        $response->assertCookie(AdAttributionService::COOKIE);

        // الكعكة مشفَّرة كسائر كعكات Laravel، ويسبق قيمتَها بادئةُ اسمها.
        $stored = json_decode(CookieValuePrefix::remove(decrypt(
            $response->getCookie(AdAttributionService::COOKIE, false)->getValue(), false
        )), true);

        $this->assertSame('A1', $stored['adset']);
        $this->assertSame('facebook', $stored['source']);
        // معرّف النقرة بصيغة المنصّة، لا القيمة الخام.
        $this->assertStringStartsWith('fb.1.', $stored['click_id']);
        $this->assertStringEndsWith('.CLICK123', $stored['click_id']);
    }

    /** وزيارةٌ عادية لا تكتب شيئًا ولا تمحو نسبةً محفوظة. */
    public function test_an_ordinary_visit_writes_nothing(): void
    {
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertCookieMissing(AdAttributionService::COOKIE);
    }

    // ────────── النسبة ──────────

    /** الطلب الإلكتروني يُنسَب إلى صفحته عبر المجموعة الإعلانية. */
    public function test_a_web_order_is_attributed_through_its_ad_set(): void
    {
        $this->link($this->product());

        $this->withCookie(AdAttributionService::COOKIE, json_encode([
            'click_id' => 'fb.1.1700000000000.CLICK123',
            'source' => 'facebook',
            'campaign' => 'C1',
            'adset' => 'A1',
        ]));

        // الطلب يُنشأ داخل طلب HTTP كي تصل الكعكة إلى نموذج الطلب.
        $order = $this->orderCreatedInRequest();

        $this->assertSame($this->channel->id, $order->ad_channel_id);
        $this->assertSame('A1', $order->ad_set_ref);
        $this->assertSame('fb.1.1700000000000.CLICK123', $order->ad_click_id);
    }

    /** ومجموعةٌ غير مربوطة لا تُخمَّن لها صفحة. */
    public function test_an_unlinked_ad_set_is_never_guessed(): void
    {
        $this->withCookie(AdAttributionService::COOKIE, json_encode(['adset' => 'GHOST']));

        $order = $this->orderCreatedInRequest();

        $this->assertNull($order->ad_channel_id);
        // ويبقى المعرّف محفوظًا: الربط قد يُستكمل لاحقًا.
        $this->assertSame('GHOST', $order->ad_set_ref);
    }

    /** وطلبٌ بلا نسبةٍ محفوظة يبقى بلا قناة — لا افتراض. */
    public function test_a_web_order_without_attribution_stays_unassigned(): void
    {
        $order = $this->orderCreatedInRequest();

        $this->assertNull($order->ad_channel_id);
        $this->assertNull($order->ad_set_ref);
    }

    /**
     * ولا تُطبَّق نسبة الويب على طلب موظفة.
     *
     * موظفة تتصفّح المتجر بعد أن نقرت إعلانًا كانت ستُهدي طلباتها اليدوية إلى
     * تلك الحملة — والصفحة تُعرف من حساب بزنسها لا من كعكة متصفّحها.
     */
    public function test_manual_orders_ignore_the_web_attribution_cookie(): void
    {
        $this->link($this->product());
        $this->withCookie(AdAttributionService::COOKIE, json_encode(['adset' => 'A1']));

        $order = $this->orderCreatedInRequest(channel: 'manual');

        $this->assertNull($order->ad_set_ref);
    }

    // ────────── قياس الشراء ──────────

    /** الشراء يُرسَل إلى منصّة القياس من الخلفية — بلا لمس مسار الإتمام. */
    public function test_a_web_order_queues_a_purchase_conversion(): void
    {
        Queue::fake();

        $this->orderCreatedInRequest();

        Queue::assertPushed(SendPurchaseConversion::class);
    }

    /** وطلب الموظفة لا يُرسَل: ليس شراءً عبر الموقع. */
    public function test_a_manual_order_sends_no_purchase_conversion(): void
    {
        Queue::fake();

        $this->orderCreatedInRequest(channel: 'manual');

        Queue::assertNothingPushed();
    }

    /** ومعرّف الحدث مشتقٌّ من الطلب فلا يُحتسب الشراء مرّتين عند إعادة المحاولة. */
    public function test_the_purchase_event_id_is_derived_from_the_order(): void
    {
        $order = $this->orderCreatedInRequest();

        app(SendPurchaseConversion::class, ['orderId' => $order->id])
            ->handle(app(ConversionTrackerManager::class));

        $event = FakeConversionTracker::first('Purchase');

        $this->assertNotNull($event);
        $this->assertSame('purchase.'.$order->uuid, $event->eventId);
        $this->assertEquals(120.0, $event->value);
    }

    /** ولا يُرسَل شيء حين لا يكون القياس مضبوطًا. */
    public function test_nothing_is_sent_when_measurement_is_not_configured(): void
    {
        FakeConversionTracker::reset(configured: false);
        $order = $this->orderCreatedInRequest();

        app(SendPurchaseConversion::class, ['orderId' => $order->id])
            ->handle(app(ConversionTrackerManager::class));

        $this->assertSame([], FakeConversionTracker::sent());
    }

    // ────────── البكسل في الصفحة ──────────

    /** لا يُطبع بكسل بلا معرّف مضبوط — المتجر يعمل كاملًا بلا قياس. */
    public function test_no_pixel_is_printed_without_an_id(): void
    {
        config()->set('ads.pixel.id', null);

        $this->assertStringNotContainsString('fbevents.js', $this->get(route('storefront.home'))->getContent());
    }

    /** وبمعرّفٍ مضبوط يُطبَع مع تهيئته. */
    public function test_the_pixel_is_printed_when_configured(): void
    {
        config()->set('ads.pixel.id', '123456789');

        $html = $this->get(route('storefront.home'))->assertOk()->getContent();

        $this->assertStringContainsString('fbevents.js', $html);
        $this->assertStringContainsString('123456789', $html);
    }

    /**
     * ينفّذ إنشاء الطلب داخل طلب HTTP.
     *
     * النموذج يقرأ الكعكة من الطلب الجاري، وإنشاؤه خارج طلبٍ لا كعكة له —
     * فالاختبار يجب أن يمرّ بمسار HTTP كما يمرّ المتجر.
     */
    private function orderCreatedInRequest(string $channel = 'web'): Order
    {
        $id = null;

        $this->app['router']->get('/__test_order', function () use ($channel, &$id) {
            $order = Order::create([
                'number' => 'SO-'.uniqid(),
                'branch_id' => Branch::default()->id,
                'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
                'customer_name' => 'زبون',
                'customer_phone' => '0599000000',
                'channel' => $channel,
                'status' => 'draft',
                'total' => 120,
            ]);

            return (string) $order->id;
        })->middleware(['web', CaptureAdAttribution::class]);

        $id = (int) $this->get('/__test_order')->assertOk()->getContent();

        return Order::findOrFail($id);
    }
}
