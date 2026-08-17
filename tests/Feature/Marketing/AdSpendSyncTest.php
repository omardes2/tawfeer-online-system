<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Marketing\Models\AdExternalMap;
use App\Modules\Marketing\Services\AdSpendSyncService;
use App\Support\Integrations\AdPlatform\AdInsightRow;
use App\Support\Integrations\AdPlatform\FakeAdPlatformProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * مزامنة الصرف الإعلاني من المنصّة.
 *
 * كلّها على المحرّك الوهمي: اختبارٌ يتّصل بـMeta الحقيقية ليس اختبارًا بل رهانٌ
 * على شبكةٍ وحسابٍ خارجيين.
 */
class AdSpendSyncTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;

    private AdChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config()->set('ads.driver', 'fake');
        FakeAdPlatformProvider::reset();

        $this->day = Carbon::yesterday();
        $this->channel = AdChannel::where('name', 'توفير اون لاين')->firstOrFail();
    }

    protected function tearDown(): void
    {
        FakeAdPlatformProvider::reset();
        parent::tearDown();
    }

    private function row(array $overrides = []): AdInsightRow
    {
        return new AdInsightRow(...array_merge([
            'date' => $this->day->toDateString(),
            'campaignId' => 'C1',
            'campaignName' => 'توفير اون لاين 15-8',
            'adsetId' => 'A1',
            'adsetName' => 'مكنسة كليكي',
            'spend' => 8.0,
            'conversations' => 12,
        ], $overrides));
    }

    private function sync(): array
    {
        return app(AdSpendSyncService::class)->sync($this->day->copy()->subDays(2), $this->day->copy());
    }

    private function link(string $adsetId, Product $product, string $campaignId = 'C1'): void
    {
        AdExternalMap::where('external_id', $campaignId)->update(['ad_channel_id' => $this->channel->id]);
        AdExternalMap::where('external_id', $adsetId)->update(['product_id' => $product->id]);
    }

    // ────────── الربط ──────────

    /** أول سحب يسجّل المعرّفات ولا يكتب صرفًا: لا شيء مربوط بعد. */
    public function test_the_first_sync_records_externals_without_writing_spend(): void
    {
        Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row()]);

        $summary = $this->sync();

        $this->assertSame(1, $summary['fetched']);
        $this->assertSame(1, $summary['unmapped']);
        $this->assertSame(0, $summary['written']);
        $this->assertSame(0, AdDailySpend::count());
        // حملةٌ ومجموعةٌ إعلانية.
        $this->assertSame(2, AdExternalMap::count());
    }

    /**
     * الاقتراح بالاسم يُسجَّل ولا يَربط.
     *
     * ربطُ صنفٍ خطأً يَنسب صرفًا إلى صنفٍ لم يُعلَن عليه — خطأٌ لا يظهر في أي رقم.
     */
    public function test_a_name_match_is_suggested_not_applied(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row(['adsetName' => 'مكنسة كليكي — نسخة'])]);

        $this->sync();

        $adset = AdExternalMap::where('external_id', 'A1')->firstOrFail();
        $this->assertSame($product->id, $adset->suggested_product_id);
        $this->assertNull($adset->product_id, 'رُبط الصنف تلقائيًّا بلا تأكيد.');

        $campaign = AdExternalMap::where('external_id', 'C1')->firstOrFail();
        $this->assertSame($this->channel->id, $campaign->suggested_ad_channel_id);
    }

    /** والتطبيع العربي يُنجح المطابقة حيث كان اختلافُ الهمزة يُفشلها. */
    public function test_the_suggestion_tolerates_arabic_spelling_differences(): void
    {
        $product = Product::factory()->create(['name' => 'جهاز تنظيف الأذن']);
        FakeAdPlatformProvider::fake([$this->row(['adsetName' => 'جهاز تنظيف الاذن - اغسطس'])]);

        $this->sync();

        $this->assertSame($product->id, AdExternalMap::where('external_id', 'A1')->value('suggested_product_id'));
    }

    // ────────── الكتابة ──────────

    public function test_a_linked_row_is_written_as_platform_sourced(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $this->link('A1', $product);
        $summary = $this->sync();

        $this->assertSame(1, $summary['written']);
        $spend = AdDailySpend::firstOrFail();
        $this->assertSame('8.00', $spend->amount_usd);
        $this->assertSame(12, $spend->conversations);
        $this->assertSame('meta', $spend->source);
        $this->assertNotNull($spend->synced_at);
    }

    /**
     * مجموعتان إعلانيتان للصنف نفسه تُجمعان لا تتدافعان.
     *
     * الكتابة المباشرة كانت ستُبقي آخر واحدة وتُسقط الأخرى، فيبدو الصرف نصفَ ما هو.
     */
    public function test_two_ad_sets_for_the_same_product_are_summed(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);

        FakeAdPlatformProvider::fake([
            $this->row(['adsetId' => 'A1', 'spend' => 8.0, 'conversations' => 12]),
            $this->row(['adsetId' => 'A2', 'adsetName' => 'مكنسة كليكي — جمهور بارد', 'spend' => 5.5, 'conversations' => 7]),
        ]);
        $this->sync();

        AdExternalMap::whereIn('external_id', ['A1', 'A2'])->update(['product_id' => $product->id]);
        AdExternalMap::where('external_id', 'C1')->update(['ad_channel_id' => $this->channel->id]);
        $this->sync();

        $this->assertSame(1, AdDailySpend::count());
        $this->assertSame('13.50', AdDailySpend::firstOrFail()->amount_usd);
        $this->assertSame(19, AdDailySpend::firstOrFail()->conversations);
    }

    /** وإعادة السحب تُحدِّث الصفّ ولا تُنشئ ثانيًا — أرقام المنصّة تُراجَع بعد نشرها. */
    public function test_resyncing_updates_the_revised_figure_in_place(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();
        $this->link('A1', $product);
        $this->sync();

        FakeAdPlatformProvider::fake([$this->row(['spend' => 9.25, 'conversations' => 14])]);
        $this->sync();

        $this->assertSame(1, AdDailySpend::count());
        $this->assertSame('9.25', AdDailySpend::firstOrFail()->amount_usd);
    }

    // ────────── التعايش مع الإدخال اليدوي ──────────

    /**
     * ما أُدخل باليد لا يُدهَس — ويُسجَّل ما تقوله المنصّة بجانبه.
     *
     * قد يكون المستخدم صحّح رقمًا؛ ودهسُه يمحو تصحيحًا مقصودًا بلا أثر.
     */
    public function test_a_manual_row_survives_the_sync_and_records_the_conflict(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();
        $this->link('A1', $product);

        $manual = AdDailySpend::create([
            'spend_date' => $this->day->copy()->startOfDay(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => $product->id,
            'amount_usd' => 5, 'fx_rate' => 3.7, 'conversations' => 3, 'source' => 'manual',
        ]);

        $summary = $this->sync();

        $manual->refresh();
        $this->assertSame('5.00', $manual->amount_usd, 'دُهست القيمة اليدوية.');
        $this->assertSame('8.00', $manual->synced_amount_usd);
        $this->assertSame(12, $manual->synced_conversations);
        $this->assertTrue($manual->conflictsWithPlatform());
        $this->assertSame(1, $summary['conflicts']);
    }

    /** وقيمةٌ يدوية مطابقة ليست تعارضًا. */
    public function test_a_matching_manual_row_is_not_a_conflict(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();
        $this->link('A1', $product);

        AdDailySpend::create([
            'spend_date' => $this->day->copy()->startOfDay(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => $product->id,
            'amount_usd' => 8, 'fx_rate' => 3.7, 'conversations' => 12, 'source' => 'manual',
        ]);

        $this->assertSame(0, $this->sync()['conflicts']);
    }

    /** وسعر صرف صفٍّ قائم لا يُعاد كتابته: ربح ذلك اليوم مُثبَّت عليه. */
    public function test_the_existing_rate_is_not_rewritten(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();
        $this->link('A1', $product);

        AdDailySpend::create([
            'spend_date' => $this->day->copy()->startOfDay(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => $product->id,
            'amount_usd' => 8, 'fx_rate' => 3.05, 'conversations' => 12, 'source' => 'meta',
        ]);

        $this->sync();

        $this->assertSame('3.0500', AdDailySpend::firstOrFail()->fx_rate);
    }

    // ────────── السلامة ──────────

    /** محرّك غير مضبوط يتوقّف بهدوء: المهمّة الليلية لا يجوز أن تفشل على نظامٍ لم يُربَط. */
    public function test_an_unconfigured_driver_is_a_quiet_no_op(): void
    {
        FakeAdPlatformProvider::fake([$this->row()], configured: false);

        $summary = $this->sync();

        $this->assertFalse($summary['configured']);
        $this->assertSame(0, AdExternalMap::count());
        $this->assertSame(0, AdDailySpend::count());
    }

    public function test_the_default_driver_is_null_so_nothing_happens_out_of_the_box(): void
    {
        config()->set('ads.driver', 'null');

        $this->assertFalse(app(AdSpendSyncService::class)->sync($this->day->copy(), $this->day->copy())['configured']);
    }

    public function test_the_command_runs_and_reports(): void
    {
        config()->set('ads.driver', 'null');

        $this->artisan('ads:sync-spend', ['--days' => 3])
            ->expectsOutputToContain('غير مربوطة')
            ->assertSuccessful();
    }

    // ────────── شاشة الربط ──────────

    public function test_the_mapping_screen_lists_pending_links_and_accepts_suggestions(): void
    {
        $product = Product::factory()->create(['name' => 'مكنسة كليكي']);
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.settings.ad_maps.index'))
            ->assertOk()
            ->assertSee('مكنسة كليكي', false)
            ->assertSee('توفير اون لاين 15-8', false);

        $this->actingAs($admin)->post(route('admin.settings.ad_maps.accept'))->assertSessionHas('success');

        $this->assertSame($product->id, AdExternalMap::where('external_id', 'A1')->value('product_id'));
        $this->assertSame($this->channel->id, AdExternalMap::where('external_id', 'C1')->value('ad_channel_id'));
    }

    /** والمُتجاهَل يخرج من قائمة الانتظار فلا يبقى يُقلق بلا سبب. */
    public function test_an_ignored_ad_set_leaves_the_pending_queue(): void
    {
        FakeAdPlatformProvider::fake([$this->row(['adsetName' => 'اختبار جمهور'])]);
        $this->sync();

        $map = AdExternalMap::where('external_id', 'A1')->firstOrFail();
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.settings.ad_maps.ignore', $map))->assertSessionHas('success');

        $this->assertTrue($map->fresh()->is_ignored);
        $this->assertSame(0, AdExternalMap::pendingLink()->where('external_id', 'A1')->count());
    }

    public function test_only_the_ad_budget_manager_reaches_the_mapping_screen(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('sales');

        $this->actingAs($user)->get(route('admin.settings.ad_maps.index'))->assertForbidden();
    }
}
