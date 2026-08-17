<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Marketing\Models\AdAutopilotDecision;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Marketing\Models\AdExternalMap;
use App\Modules\Marketing\Services\AdAutopilotService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Support\Integrations\AdPlatform\AdPlatformManager;
use App\Support\Integrations\AdPlatform\AdSetState;
use App\Support\Integrations\AdPlatform\FakeAdPlatformWriter;
use App\Support\Integrations\AdPlatform\NullAdPlatformWriter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

/**
 * الطيّار الآلي للإعلانات.
 *
 * ما تحرسه هذه الاختبارات ليس أن الطيّار **يتصرّف**، بل أنه **لا يتصرّف حيث لا
 * يجوز**: لا يرفع ميزانية، ولا يلمس صفحةً لم تُسلَّم إليه، ولا يكتب وهو في وضع
 * الاقتراح، ولا يتجاوز السقف. وأكثر أخطاء الأتمتة كلفةً هي التي لا يشتكي منها
 * أحد لأنها تصرف مالًا بهدوء.
 *
 * وكلّها على محرّك كتابةٍ وهمي — اختبارٌ يكتب إلى Meta الحقيقية ليس اختبارًا.
 */
class AdAutopilotTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;

    private AdChannel $channel;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config()->set('ads.write.driver', 'fake');
        FakeAdPlatformWriter::reset();

        $this->day = Carbon::yesterday();

        $business = DeliveryBusiness::create([
            'provider' => 'opost', 'external_id' => 'biz-1', 'name' => 'توفير اون لاين', 'is_active' => true,
        ]);

        $this->channel = AdChannel::where('name', 'توفير اون لاين')->firstOrFail();
        $this->channel->update(['delivery_business_id' => $business->id, 'autopilot_enabled' => true]);

        $this->employee = User::factory()->create([
            'branch_id' => Branch::default()->id,
            'delivery_business_id' => $business->id,
        ]);

        // الافتراضات الآمنة تُفتح صراحةً هنا، فيبقى إغلاقها قابلًا للاختبار.
        Settings::set('ads.autopilot.enabled', true, 'ads', 'boolean');
        Settings::set('ads.autopilot.mode', 'brake', 'ads', 'string');
        Settings::set('ads.autopilot.daily_cap', 100, 'ads', 'double');
    }

    protected function tearDown(): void
    {
        FakeAdPlatformWriter::reset();
        parent::tearDown();
    }

    // ────────── تجهيز ──────────

    private function product(string $name = 'مكنسة كليكي'): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $product->defaultVariant->update(['average_cost' => 40, 'retail_price' => 100]);

        return $product;
    }

    /** ربط الحملة بالصفحة والمجموعة بالصنف — كما تفعله شاشة «ربط الحملات». */
    private function link(Product $product, string $adsetId = 'A1', string $campaignId = 'C1'): void
    {
        AdExternalMap::create([
            'provider' => 'fake', 'external_type' => AdExternalMap::TYPE_CAMPAIGN,
            'external_id' => $campaignId, 'external_name' => 'حملة توفير',
            'ad_channel_id' => $this->channel->id,
        ]);

        AdExternalMap::create([
            'provider' => 'fake', 'external_type' => AdExternalMap::TYPE_ADSET,
            'external_id' => $adsetId, 'external_name' => $product->name,
            'parent_external_id' => $campaignId, 'product_id' => $product->id,
        ]);
    }

    private function adSet(string $id = 'A1', ?float $budget = 40.0, string $status = 'ACTIVE'): AdSetState
    {
        return new AdSetState(
            id: $id, name: 'مجموعة '.$id, status: $status, effectiveStatus: $status,
            dailyBudget: $budget, lifetimeBudget: null, campaignId: 'C1', currency: 'USD',
        );
    }

    private function spend(Product $product, float $usd, int $conversations, ?Carbon $on = null): void
    {
        AdDailySpend::create([
            'spend_date' => ($on ?? $this->day)->copy()->startOfDay(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => $product->id,
            'amount_usd' => $usd,
            'fx_rate' => 4,
            'conversations' => $conversations,
        ]);
    }

    private function sell(Product $product, ?Carbon $on = null): Order
    {
        $this->actingAs($this->employee);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
        ], [['variant_id' => $product->defaultVariant->id, 'qty' => 1, 'unit_price' => 100]], 2026);

        $order->forceFill([
            'status' => 'delivered',
            'created_at' => ($on ?? $this->day)->copy()->setTime(12, 0),
        ])->save();

        return $order->refresh();
    }

    private function pilot(bool $dryRun = false): array
    {
        return app(AdAutopilotService::class)->run($this->day->copy(), null, $dryRun);
    }

    // ────────── الفرملة ──────────

    /** صرفٌ بلا محادثة واحدة ⇒ «أوقف» ⇒ إيقافٌ فعلي على المنصّة. */
    public function test_it_pauses_an_ad_set_the_verdict_condemns(): void
    {
        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $summary = $this->pilot();

        $this->assertSame(1, $summary['paused']);
        $this->assertSame([['pause', 'A1', null]], FakeAdPlatformWriter::calls());
        $this->assertSame('PAUSED', FakeAdPlatformWriter::state('A1')->status);

        $decision = AdAutopilotDecision::firstOrFail();
        $this->assertSame(AdAutopilotDecision::ACTION_PAUSE, $decision->action);
        $this->assertSame(AdAutopilotDecision::STATUS_APPLIED, $decision->status);
        // دليل القرار محفوظٌ معه: 20$ × 4 = 80 ₪.
        $this->assertEquals(80.0, (float) $decision->window_spend);
    }

    /**
     * حكم «أنقص» يُخفّض بالنسبة المضبوطة لا أكثر.
     *
     * والسقف عند 20% ليس تحفّظًا: ما فوقه يُعيد المجموعة إلى مرحلة التعلّم لدى
     * المنصّة، فيصير التدخّل أضرّ من تركها.
     */
    public function test_it_decreases_the_budget_by_the_configured_share_only(): void
    {
        $product = $this->product();
        $this->link($product);

        // 10 طلبات على نافذة 3 أيام بصرفٍ يجعل تكلفة الطلب بين «ثبّت» و«أوقف».
        for ($i = 0; $i < 10; $i++) {
            $this->sell($product);
        }
        $this->spend($product, 125, 30);     // 500 ₪ ⇒ 50 ₪ للطلب ⇒ «أنقص»

        FakeAdPlatformWriter::fake([$this->adSet(budget: 40.0)]);

        $summary = $this->pilot();

        $this->assertSame(1, $summary['decreased']);
        $this->assertSame([['budget', 'A1', 32.0]], FakeAdPlatformWriter::calls());

        $decision = AdAutopilotDecision::firstOrFail();
        $this->assertEquals(40.0, (float) $decision->budget_before);
        $this->assertEquals(32.0, (float) $decision->budget_after);
    }

    /** ولا يرفع ميزانيةً أبدًا — حتى حين يقول الحكم «زد». */
    public function test_it_never_raises_a_budget_even_when_the_verdict_says_increase(): void
    {
        $product = $this->product();
        $this->link($product);

        for ($i = 0; $i < 10; $i++) {
            $this->sell($product);
        }
        $this->spend($product, 25, 30);      // 100 ₪ ⇒ 10 ₪ للطلب ⇒ «زد»

        FakeAdPlatformWriter::fake([$this->adSet()]);

        $summary = $this->pilot();

        $this->assertSame(0, $summary['applied']);
        $this->assertSame([], FakeAdPlatformWriter::calls());

        $decision = AdAutopilotDecision::firstOrFail();
        $this->assertSame(AdAutopilotDecision::ACTION_SKIP, $decision->action);
        $this->assertStringContainsString('القرار لك', $decision->reason);
    }

    // ────────── الأقفال ──────────

    /** وضع «الاقتراح» يكتب القرار ولا يلمس المنصّة. */
    public function test_suggest_mode_writes_the_decision_but_touches_nothing(): void
    {
        Settings::set('ads.autopilot.mode', 'suggest', 'ads', 'string');

        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $summary = $this->pilot();

        $this->assertSame(1, $summary['planned']);
        $this->assertSame(0, $summary['applied']);
        $this->assertSame([], FakeAdPlatformWriter::calls());
        $this->assertSame(AdAutopilotDecision::STATUS_PLANNED, AdAutopilotDecision::firstOrFail()->status);
    }

    /** والمفتاح الرئيسي يمنع حتى التخطيط. */
    public function test_the_master_switch_stops_everything(): void
    {
        Settings::set('ads.autopilot.enabled', false, 'ads', 'boolean');

        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $summary = $this->pilot();

        $this->assertFalse($summary['enabled']);
        $this->assertSame(0, $summary['planned']);
        $this->assertSame(0, AdAutopilotDecision::count());
        $this->assertSame([], FakeAdPlatformWriter::calls());
    }

    /** وصفحةٌ لم تُسلَّم إلى الطيّار لا يُمَسّ إعلانها. */
    public function test_it_ignores_channels_not_handed_to_it(): void
    {
        $this->channel->update(['autopilot_enabled' => false]);

        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $summary = $this->pilot();

        $this->assertSame(0, $summary['channels']);
        $this->assertSame([], FakeAdPlatformWriter::calls());
    }

    /** و«التجربة بلا تنفيذ» تُظهر الخطة ولا ترسلها. */
    public function test_a_dry_run_plans_without_writing(): void
    {
        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $summary = $this->pilot(dryRun: true);

        $this->assertSame(1, $summary['planned']);
        $this->assertSame([], FakeAdPlatformWriter::calls());
    }

    // ────────── القفص ──────────

    /**
     * السقف اليومي يُوقف الأقلّ ربحًا حتى ينزل المجموع تحته.
     *
     * والصنف الرابح يبقى: السقف ليس تخفيضًا شاملًا بل قصٌّ من الطرف الخاسر.
     */
    public function test_the_daily_cap_pauses_the_least_profitable_first(): void
    {
        Settings::set('ads.autopilot.daily_cap', 50, 'ads', 'double');

        $winner = $this->product('رابح');
        $loser = $this->product('خاسر');
        $this->link($winner, 'A1', 'C1');

        AdExternalMap::create([
            'provider' => 'fake', 'external_type' => AdExternalMap::TYPE_ADSET,
            'external_id' => 'A2', 'external_name' => 'خاسر',
            'parent_external_id' => 'C1', 'product_id' => $loser->id,
        ]);

        // الرابح: 10 طلبات بصرفٍ خفيف ⇒ صافٍ موجب. الخاسر: طلبٌ واحد بصرفٍ ثقيل.
        for ($i = 0; $i < 10; $i++) {
            $this->sell($winner);
        }
        $this->spend($winner, 25, 30);
        $this->sell($loser);
        $this->spend($loser, 30, 5);

        FakeAdPlatformWriter::fake([
            $this->adSet('A1', 40.0),
            $this->adSet('A2', 40.0),
        ]);

        $summary = $this->pilot();

        $this->assertTrue($summary['cap_breach']);
        // أُوقف الخاسر وحده، وبقي الرابح يعمل.
        $this->assertSame([['pause', 'A2', null]], FakeAdPlatformWriter::calls());
        $this->assertSame('ACTIVE', FakeAdPlatformWriter::state('A1')->status);
    }

    /** ولا تُلمَس ميزانيةٌ مضبوطة على مستوى الحملة — تعديلها يطال مجموعاتٍ أخرى. */
    public function test_it_refuses_to_touch_a_campaign_level_budget(): void
    {
        $product = $this->product();
        $this->link($product);

        for ($i = 0; $i < 10; $i++) {
            $this->sell($product);
        }
        $this->spend($product, 125, 30);     // «أنقص»

        FakeAdPlatformWriter::fake([$this->adSet(budget: null)]);

        $summary = $this->pilot();

        $this->assertSame(0, $summary['applied']);
        $this->assertSame([], FakeAdPlatformWriter::calls());
        $this->assertStringContainsString('مستوى الحملة', AdAutopilotDecision::firstOrFail()->reason);
    }

    /** والتهدئة تمنع تخفيضًا ثانيًا خلال أيامها. */
    public function test_a_recent_budget_change_puts_the_ad_set_in_cooldown(): void
    {
        $product = $this->product();
        $this->link($product);

        for ($i = 0; $i < 10; $i++) {
            $this->sell($product);
        }
        $this->spend($product, 125, 30);

        AdAutopilotDecision::create([
            'decided_on' => Carbon::today()->subDay()->toDateString(),
            'report_day' => $this->day->copy()->subDay()->toDateString(),
            'external_id' => 'A1',
            'action' => AdAutopilotDecision::ACTION_DECREASE,
            'reason' => 'تخفيض أمس',
            'status' => AdAutopilotDecision::STATUS_APPLIED,
        ]);

        FakeAdPlatformWriter::fake([$this->adSet()]);

        $this->pilot();

        $this->assertSame([], FakeAdPlatformWriter::calls());
        $this->assertStringContainsString('تهدئة', AdAutopilotDecision::where('external_id', 'A1')
            ->whereDate('decided_on', Carbon::today())->firstOrFail()->reason);
    }

    /** ومجموعةٌ موقوفة أصلًا لا تُوقَف مرّة أخرى. */
    public function test_an_already_paused_ad_set_is_left_alone(): void
    {
        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet(status: 'PAUSED')]);

        $this->pilot();

        $this->assertSame([], FakeAdPlatformWriter::calls());
    }

    /** وتشغيل الدورة مرّتين في اليوم نفسه لا يُنفّذ القرار مرّتين. */
    public function test_running_twice_in_a_day_does_not_act_twice(): void
    {
        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $this->pilot();
        $this->pilot();

        // الثاني يجد المجموعة موقوفة فيتخطّاها، والصفّ واحد لا اثنان.
        $this->assertCount(1, FakeAdPlatformWriter::calls());
        $this->assertSame(1, AdAutopilotDecision::count());
    }

    // ────────── الحاجز بين القراءة والكتابة ──────────

    /** محرّك الكتابة الافتراضي لا يكتب — ويرفض بصوتٍ مسموع لا بصمت. */
    public function test_the_default_writer_refuses_to_write(): void
    {
        $writer = new NullAdPlatformWriter;

        $this->assertFalse($writer->isConfigured());
        $this->expectException(LogicException::class);
        $writer->pause('A1');
    }

    /** ورمز القراءة وحده لا يجعل الكتابة جاهزة. */
    public function test_a_read_token_alone_does_not_enable_writing(): void
    {
        config()->set('ads.write.driver', 'meta');
        config()->set('ads.meta.token', 'read-token');
        config()->set('ads.meta.account_id', '123');
        config()->set('ads.write.token', null);

        $this->assertFalse(app(AdPlatformManager::class)->writer()->isConfigured());
    }

    // ────────── التراجع والإيقاف الطارئ ──────────

    /** التراجع يعيد ما كان بالضبط لا ما يُحسب من جديد. */
    public function test_reverting_restores_the_previous_state(): void
    {
        $product = $this->product();
        $this->link($product);
        $this->spend($product, 20, 0);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $this->pilot();
        $decision = AdAutopilotDecision::firstOrFail();

        app(AdAutopilotService::class)->revert($decision, $this->employee->id);

        $this->assertSame('ACTIVE', FakeAdPlatformWriter::state('A1')->status);
        $this->assertSame(AdAutopilotDecision::STATUS_REVERTED, $decision->refresh()->status);
        $this->assertSame($this->employee->id, $decision->reverted_by);
    }

    /** والإيقاف الطارئ يطال كل مجموعة مربوطة، لا الصفحات المُسلَّمة وحدها. */
    public function test_the_emergency_stop_pauses_every_linked_ad_set(): void
    {
        $this->channel->update(['autopilot_enabled' => false]);

        $product = $this->product();
        $this->link($product);
        FakeAdPlatformWriter::fake([$this->adSet()]);

        $result = app(AdAutopilotService::class)->stopAll($this->employee->id);

        $this->assertSame(1, $result['stopped']);
        $this->assertSame('PAUSED', FakeAdPlatformWriter::state('A1')->status);
        $this->assertSame('manual', AdAutopilotDecision::firstOrFail()->source);
    }

    /** وفشلُ مجموعةٍ لا يوقف الدورة: الباقي يُنفَّذ ويُسجَّل الفشل. */
    public function test_one_failure_does_not_stop_the_run(): void
    {
        $first = $this->product('أول');
        $second = $this->product('ثاني');
        $this->link($first, 'A1', 'C1');

        AdExternalMap::create([
            'provider' => 'fake', 'external_type' => AdExternalMap::TYPE_ADSET,
            'external_id' => 'A2', 'external_name' => 'ثاني',
            'parent_external_id' => 'C1', 'product_id' => $second->id,
        ]);

        $this->spend($first, 20, 0);
        $this->spend($second, 20, 0);

        FakeAdPlatformWriter::fake([$this->adSet('A1'), $this->adSet('A2')]);
        FakeAdPlatformWriter::failOn('A1', 'رفضت المنصّة الطلب.');

        $summary = $this->pilot();

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['applied']);
        $this->assertSame('PAUSED', FakeAdPlatformWriter::state('A2')->status);

        $failed = AdAutopilotDecision::where('external_id', 'A1')->firstOrFail();
        $this->assertSame(AdAutopilotDecision::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('رفضت المنصّة', $failed->error);
    }

    // ────────── الشاشة ──────────

    /** الصفحة تُفتح للمخوَّل وتُغلق على غيره. */
    public function test_the_page_is_gated_by_permission(): void
    {
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.marketing.autopilot.index'))->assertOk();

        $outsider = User::factory()->create(['branch_id' => Branch::default()->id]);
        $outsider->assignRole('warehouse');
        $this->actingAs($outsider)->get(route('admin.marketing.autopilot.index'))->assertForbidden();
    }

    /** والسقف يُضبط من اللوحة لا من الخادم. */
    public function test_the_daily_cap_is_editable_from_the_dashboard(): void
    {
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.marketing.autopilot.settings'), [
            'enabled' => '1',
            'mode' => 'brake',
            'daily_cap' => 250.5,
            'max_decrease_pct' => 15,
            'cooldown_days' => 2,
            'min_budget' => 8,
            'channels' => [$this->channel->id],
        ])->assertRedirect();

        $settings = app(AdAutopilotService::class)->settings();

        $this->assertSame(250.5, $settings['daily_cap']);
        $this->assertSame(15, $settings['max_decrease_pct']);
        $this->assertTrue($this->channel->refresh()->autopilot_enabled);
    }

    /** وسحبُ صفحةٍ من الطيّار يتمّ بإلغاء اختيارها. */
    public function test_unchecking_a_channel_takes_it_back_from_the_autopilot(): void
    {
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.marketing.autopilot.settings'), [
            'mode' => 'suggest',
            'daily_cap' => 100,
            'max_decrease_pct' => 20,
            'cooldown_days' => 3,
            'min_budget' => 5,
        ])->assertRedirect();

        $this->assertFalse($this->channel->refresh()->autopilot_enabled);
        $this->assertSame(0, AdChannel::where('autopilot_enabled', true)->count());
    }
}
