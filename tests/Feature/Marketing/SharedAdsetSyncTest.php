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
 * مجموعةٌ إعلانية واحدة لعدّة أصناف.
 *
 * الواقع في مدير إعلانات ميتا أن المجموعة الواحدة تُعلن أحيانًا عن ثلاثة أصناف
 * بميزانيةٍ واحدة. وربطُها بصنفٍ واحد يُحمّله إنفاق الثلاثة فيظهر خاسرًا وهما،
 * وتركُها بلا ربطٍ يُسقط إنفاقها من الحساب كلّه.
 *
 * والتوزيع يقع **عند القراءة** بحصّة مبيعات كلٍّ منها، لا هنا بقسمةٍ متساوية
 * تُنتج ربحًا وهميًّا لصنفٍ لم يبع.
 */
class SharedAdsetSyncTest extends TestCase
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
            'campaignName' => 'حملة',
            'adsetId' => 'A1',
            'adsetName' => 'عرض الشتاء',
            'spend' => 30.0,
            'conversations' => 9,
        ], $overrides));
    }

    private function sync(): array
    {
        return app(AdSpendSyncService::class)->sync($this->day->copy()->subDays(2), $this->day->copy());
    }

    /** @param  array<int, Product>  $products */
    private function link(array $products, string $adsetId = 'A1'): void
    {
        AdExternalMap::where('external_id', 'C1')->update(['ad_channel_id' => $this->channel->id]);

        $map = AdExternalMap::where('external_id', $adsetId)->firstOrFail();
        $map->update(['product_id' => count($products) === 1 ? $products[0]->id : null]);
        $map->products()->sync(collect($products)->pluck('id')->all());
    }

    private function product(string $name): Product
    {
        return Product::factory()->create(['name' => $name]);
    }

    /** مجموعةٌ لعدّة أصناف تُكتب صفًّا مشتركًا واحدًا لا صفًّا لكل صنف. */
    public function test_a_shared_adset_writes_one_shared_row(): void
    {
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();   // أول سحب: يسجّل المعرّفات

        $this->link([$this->product('مكنسة'), $this->product('مروحة')]);

        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $rows = AdDailySpend::whereDate('spend_date', $this->day)->get();

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->product_id);
        $this->assertSame('عرض الشتاء', $rows->first()->label);
        $this->assertEqualsWithDelta(30, (float) $rows->first()->amount_usd, 0.01);
    }

    /** وأصنافه تُسجَّل عليه. */
    public function test_the_shared_row_records_its_products(): void
    {
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $a = $this->product('مكنسة');
        $b = $this->product('مروحة');
        $this->link([$a, $b]);

        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $row = AdDailySpend::whereDate('spend_date', $this->day)->firstOrFail();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $row->productIds());
        $this->assertTrue($row->isShared());
    }

    /**
     * والإنفاق لا يُضاعَف: ٣٠ تبقى ٣٠ لا ٦٠.
     *
     * هذا هو الخطأ الذي تمنعه الميزة: صفٌّ لكل صنفٍ بكامل المبلغ يُظهر إنفاقًا
     * ضعف الحقيقي في تقرير الميزانية.
     */
    public function test_the_spend_is_not_duplicated_across_products(): void
    {
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $this->link([$this->product('مكنسة'), $this->product('مروحة'), $this->product('غلاية')]);

        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $this->assertEqualsWithDelta(
            30,
            (float) AdDailySpend::whereDate('spend_date', $this->day)->sum('amount_usd'),
            0.01,
        );
    }

    /** ومجموعةٌ لصنفٍ واحد تبقى كما كانت — صفًّا مفردًا بلا عنوان. */
    public function test_a_single_product_adset_still_writes_a_plain_row(): void
    {
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $product = $this->product('مكنسة');
        $this->link([$product]);

        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $row = AdDailySpend::whereDate('spend_date', $this->day)->firstOrFail();

        $this->assertSame($product->id, $row->product_id);
        $this->assertNull($row->label);
        $this->assertFalse($row->isShared());
    }

    /** وإعادة المزامنة لا تُنشئ صفًّا ثانيًا للمجموعة نفسها. */
    public function test_re_syncing_updates_the_same_shared_row(): void
    {
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $this->link([$this->product('مكنسة'), $this->product('مروحة')]);

        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        FakeAdPlatformProvider::fake([$this->row(['spend' => 45.0])]);
        $this->sync();

        $rows = AdDailySpend::whereDate('spend_date', $this->day)->get();

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(45, (float) $rows->first()->amount_usd, 0.01);
    }

    /** ومجموعتان مشتركتان لا تدهس إحداهما الأخرى. */
    public function test_two_shared_adsets_stay_separate(): void
    {
        FakeAdPlatformProvider::fake([
            $this->row(),
            $this->row(['adsetId' => 'A2', 'adsetName' => 'عرض الصيف', 'spend' => 20.0]),
        ]);
        $this->sync();

        $this->link([$this->product('مكنسة'), $this->product('مروحة')], 'A1');
        $this->link([$this->product('غلاية'), $this->product('خلاط')], 'A2');

        FakeAdPlatformProvider::fake([
            $this->row(),
            $this->row(['adsetId' => 'A2', 'adsetName' => 'عرض الصيف', 'spend' => 20.0]),
        ]);
        $this->sync();

        $rows = AdDailySpend::whereDate('spend_date', $this->day)->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['عرض الشتاء', 'عرض الصيف'], $rows->pluck('label')->all());
    }

    // ────────── الشاشة ──────────

    /** والشاشة تحفظ عدّة أصناف لمجموعةٍ واحدة. */
    public function test_the_screen_saves_several_products_for_one_adset(): void
    {
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $map = AdExternalMap::where('external_id', 'A1')->firstOrFail();
        $a = $this->product('مكنسة');
        $b = $this->product('مروحة');

        $this->actingAs($admin)
            ->put(route('admin.settings.ad_maps.update', $map), ['product_ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $map->fresh()->productIds());
        // العمود القديم يُفرَّغ عند التعدّد: صنفٌ واحد فيه يُقرأ ربطًا مفردًا.
        $this->assertNull($map->fresh()->product_id);
    }

    /** ويبقى العمود القديم مملوءًا حين يكون الصنف واحدًا. */
    public function test_a_single_selection_still_fills_the_legacy_column(): void
    {
        FakeAdPlatformProvider::fake([$this->row()]);
        $this->sync();

        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $map = AdExternalMap::where('external_id', 'A1')->firstOrFail();
        $product = $this->product('مكنسة');

        $this->actingAs($admin)
            ->put(route('admin.settings.ad_maps.update', $map), ['product_ids' => [$product->id]])
            ->assertRedirect();

        $this->assertSame($product->id, $map->fresh()->product_id);
    }

    /** وبرانش المستخدم لا يمنع الوصول — الصلاحية وحدها. */
    public function test_the_screen_is_closed_to_other_roles(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('sales');

        $this->actingAs($user)->get(route('admin.settings.ad_maps.index'))->assertForbidden();
    }
}
