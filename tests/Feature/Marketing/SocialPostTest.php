<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\SocialPost;
use App\Modules\Marketing\Services\AdAttributionService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة محتوى المنشورات.
 *
 * غايتها شيئان: نصٌّ يُنسخ، و**رابطٌ يُعرَف أثره**. والثاني أهمّ: منشورٌ برابطٍ
 * عارٍ يبيع ولا يُعرَف أنه باع — يسقط طلبُه تحت «غير منسوب» فيبدو الإعلان
 * المدفوع وحده هو الذي يعمل، ويُوقَف النشر العضويّ لأنه «بلا نتيجة».
 *
 * وما تحرسه هذه الاختبارات قبل ذلك: **أن الصفحة لا تنشر شيئًا**.
 */
class SocialPostTest extends TestCase
{
    use RefreshDatabase;

    private AdChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->channel = AdChannel::where('name', 'توفير اون لاين')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function product(): Product
    {
        return Product::factory()->create(['name' => 'مكنسة كليكي']);
    }

    // ────────── الصلاحيات ──────────

    /** الصفحة تُفتح للمخوَّل وتُغلق على غيره. */
    public function test_the_page_is_gated_by_permission(): void
    {
        $this->actingAs($this->admin())->get(route('admin.marketing.social.index'))->assertOk();

        $outsider = User::factory()->create(['branch_id' => Branch::default()->id]);
        $outsider->assignRole('warehouse');

        $this->actingAs($outsider)->get(route('admin.marketing.social.index'))->assertForbidden();
    }

    // ────────── الرابط المتتبَّع ──────────

    /** المنشور يُحفَظ برابطٍ يحمل وسم صفحته. */
    public function test_a_saved_post_carries_a_tracked_link(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post(route('admin.marketing.social.store'), [
            'product_id' => $product->id,
            'ad_channel_id' => $this->channel->id,
            'platform' => 'facebook',
            'locale' => 'ar',
            'body' => 'منشور تجريبي',
            'status' => 'draft',
        ])->assertRedirect();

        $post = SocialPost::firstOrFail();

        $this->assertStringContainsString($product->slug, $post->link);
        $this->assertStringContainsString('utm_source=facebook', $post->link);
        $this->assertStringContainsString(AdAttributionService::channelToken($this->channel->id), $post->link);
    }

    /** ومنشور إنستغرام يحمل مصدره هو. */
    public function test_an_instagram_post_carries_its_own_source(): void
    {
        $this->actingAs($this->admin())->post(route('admin.marketing.social.store'), [
            'product_id' => $this->product()->id,
            'ad_channel_id' => $this->channel->id,
            'platform' => 'instagram',
            'locale' => 'ar',
            'body' => 'منشور',
            'status' => 'draft',
        ])->assertRedirect();

        $this->assertStringContainsString('utm_source=instagram', SocialPost::firstOrFail()->link);
    }

    // ────────── وهذا هو المقصد ──────────

    /**
     * الطلب القادم من رابط المنشور يُنسَب إلى صفحته.
     *
     * وهذه الحلقة كلّها: يُنشَر رابطٌ موسوم ← ينقره زبون ← يشتري ← يظهر الطلب
     * في «الميزانية اليومية» تحت الصفحة الصحيحة. وبلا الوسم تنقطع عند أوّلها.
     */
    public function test_an_order_from_a_post_link_is_attributed_to_its_page(): void
    {
        $token = AdAttributionService::channelToken($this->channel->id);

        // زيارةٌ من رابط المنشور تكتب النسبة.
        $this->get(route('storefront.home', ['utm_source' => 'facebook', 'utm_campaign' => $token]))->assertOk();

        $this->withCookie(AdAttributionService::COOKIE, json_encode([
            'source' => 'facebook',
            'campaign' => $token,
        ]));

        $order = $this->webOrderInRequest();

        $this->assertSame($this->channel->id, $order->ad_channel_id);
        $this->assertSame($token, $order->ad_campaign_ref);
    }

    /** ورمزٌ لقناةٍ لا وجود لها لا يُنسَب إليه شيء. */
    public function test_a_token_for_a_missing_channel_attributes_nothing(): void
    {
        $this->withCookie(AdAttributionService::COOKIE, json_encode([
            'source' => 'facebook',
            'campaign' => 'tw-ch-99999',
        ]));

        $this->assertNull($this->webOrderInRequest()->ad_channel_id);
    }

    // ────────── لا نشر ──────────

    /** «تمّ نشره» علامةٌ يضعها إنسان — لا نشرٌ من النظام. */
    public function test_marking_published_only_stamps_the_record(): void
    {
        $post = SocialPost::create([
            'product_id' => $this->product()->id,
            'platform' => 'facebook', 'locale' => 'ar',
            'body' => 'منشور', 'status' => 'ready',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.marketing.social.published', $post))
            ->assertRedirect();

        $post->refresh();

        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
    }

    /** ولا نقطة نهايةٍ في النظام تنشر إلى المنصّة. */
    public function test_no_route_publishes_to_the_platform(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($r) => (string) $r->getName())
            // `marketing.social.` لا `social.` وحدها: الثانية تلتقط مسارات
            // تسجيل الدخول بالحسابات الاجتماعية، وهي شأنٌ آخر تمامًا.
            ->filter(fn (string $n) => str_contains($n, 'marketing.social.'))
            ->sort()->values()->all();

        $this->assertSame([
            'admin.marketing.social.destroy',
            'admin.marketing.social.index',
            'admin.marketing.social.link',
            'admin.marketing.social.published',
            'admin.marketing.social.store',
            'admin.marketing.social.suggest',
            'admin.marketing.social.update',
        ], $names);
    }

    /** والاقتراح لا يحفظ شيئًا من تلقائه. */
    public function test_suggesting_saves_nothing(): void
    {
        $response = $this->actingAs($this->admin())->postJson(route('admin.marketing.social.suggest'), [
            'product_id' => $this->product()->id,
            'platform' => 'facebook',
            'locale' => 'ar',
        ])->assertOk();

        $response->assertJson(['saved' => false]);
        $this->assertSame(0, SocialPost::count());
    }

    /** ينفّذ إنشاء طلب ويبٍ داخل طلب HTTP كي تصل الكعكة إلى النموذج. */
    private function webOrderInRequest(): Order
    {
        $this->app['router']->get('/__test_social_order', function () {
            return (string) Order::create([
                'number' => 'SO-'.uniqid(),
                'branch_id' => Branch::default()->id,
                'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
                'customer_name' => 'زبون',
                'customer_phone' => '0599000000',
                'channel' => 'web',
                'status' => 'draft',
                'total' => 100,
            ])->id;
        })->middleware(['web']);

        return Order::findOrFail((int) $this->get('/__test_social_order')->assertOk()->getContent());
    }
}
