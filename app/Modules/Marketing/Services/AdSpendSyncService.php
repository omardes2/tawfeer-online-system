<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Marketing\Models\AdExternalMap;
use App\Support\Integrations\AdPlatform\AdInsightRow;
use App\Support\Integrations\AdPlatform\AdPlatformManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة الصرف الإعلاني من المنصّة إلى `ad_daily_spends`.
 *
 * ثلاث قواعد تحكم هذه الخدمة:
 *
 * 1. **لا تُكتب صفوفٌ غير مربوطة.** المجموعة الإعلانية بلا صنفٍ مؤكَّد تُسجَّل في
 *    قائمة الانتظار ولا تُخمَّن. صرفٌ منسوبٌ لصنفٍ خاطئ لا يظهر في أي رقم —
 *    يبدو كلُّ شيء سليمًا ويكون القرار مبنيًّا على وهم.
 *
 * 2. **الجمع قبل الكتابة.** مجموعتان إعلانيتان تُعلنان الصنف نفسه على الصفحة
 *    نفسها تُجمعان في صفٍّ واحد — الكتابة المباشرة كانت ستُبقي آخر واحدة وتُسقط
 *    الأخرى، فيبدو الصرف نصفَ ما هو.
 *
 * 3. **اليدويّ لا يُدهَس.** ما أدخله المستخدم يبقى، وتُسجَّل قيمة المنصّة بجانبه
 *    ليُعرَض الاختلاف ويقرّر هو.
 */
class AdSpendSyncService
{
    public function __construct(private readonly AdPlatformManager $platform) {}

    /**
     * @return array{configured: bool, provider: string, fetched: int, written: int,
     *               unmapped: int, conflicts: int, new_maps: int}
     */
    public function sync(Carbon $from, Carbon $to): array
    {
        $provider = $this->platform->provider();

        $summary = [
            'configured' => $provider->isConfigured(),
            'provider' => $provider->name(),
            'fetched' => 0, 'written' => 0, 'unmapped' => 0, 'conflicts' => 0, 'new_maps' => 0,
        ];

        if (! $provider->isConfigured()) {
            return $summary;
        }

        $rows = $provider->dailyInsights($from, $to);
        $summary['fetched'] = $rows->count();

        if ($rows->isEmpty()) {
            return $summary;
        }

        $summary['new_maps'] = $this->recordExternals($rows);

        $channels = $this->linkedIds(AdExternalMap::TYPE_CAMPAIGN, 'ad_channel_id');
        $products = $this->linkedIds(AdExternalMap::TYPE_ADSET, 'product_id');

        // الجمع على (يوم، قناة، صنف) قبل أي كتابة — القاعدة 2 أعلاه.
        $buckets = [];

        foreach ($rows as $row) {
            $channelId = $channels[$row->campaignId] ?? null;
            $productId = $products[$row->adsetId] ?? null;

            if ($channelId === null || $productId === null) {
                $summary['unmapped']++;

                continue;
            }

            $key = $row->date.':'.$channelId.':'.$productId;
            $buckets[$key] ??= ['date' => $row->date, 'channel' => $channelId, 'product' => $productId, 'spend' => 0.0, 'conversations' => 0];
            $buckets[$key]['spend'] += $row->spend;
            $buckets[$key]['conversations'] += $row->conversations;
        }

        $rate = (float) Settings::get('ads.usd_rate', 3.7);

        DB::transaction(function () use ($buckets, $rate, &$summary) {
            foreach ($buckets as $bucket) {
                $summary[$this->write($bucket, $rate)]++;
            }
        });

        return $summary;
    }

    /**
     * كتابة صفٍّ واحد — ويعيد أي عدّاد يزيد.
     *
     * @param  array{date: string, channel: int, product: int, spend: float, conversations: int}  $bucket
     */
    private function write(array $bucket, float $rate): string
    {
        $spend = round($bucket['spend'], 2);

        $existing = AdDailySpend::query()
            ->whereDate('spend_date', $bucket['date'])
            ->where('ad_channel_id', $bucket['channel'])
            ->where('product_id', $bucket['product'])
            ->first();

        $synced = [
            'synced_amount_usd' => $spend,
            'synced_conversations' => $bucket['conversations'],
            'synced_at' => now(),
        ];

        // اليدويّ يبقى: تُسجَّل قيمة المنصّة بجانبه وحسب.
        if ($existing && $existing->source === 'manual') {
            $existing->update($synced);

            $differs = abs((float) $existing->amount_usd - $spend) >= 0.01
                || (int) $existing->conversations !== $bucket['conversations'];

            return $differs ? 'conflicts' : 'written';
        }

        AdDailySpend::updateOrCreate(
            [
                'spend_date' => Carbon::parse($bucket['date'])->startOfDay(),
                'ad_channel_id' => $bucket['channel'],
                'product_id' => $bucket['product'],
            ],
            $synced + [
                'amount_usd' => $spend,
                'conversations' => $bucket['conversations'],
                'source' => 'meta',
                // سعر صرف الصفّ القائم لا يُعاد كتابته: ربح ذلك اليوم مُثبَّت عليه.
                'fx_rate' => $existing?->fx_rate ?? $rate,
            ],
        );

        return 'written';
    }

    /**
     * تسجيل كل معرّف خارجي ظهر، مع مرشَّحٍ مقترح بالاسم — ويعيد عدد الجديد.
     *
     * `updateOrCreate` لا `firstOrCreate`: الاسم يتغيّر على المنصّة، ونريد آخر
     * اسمٍ للعرض. أمّا الربط المؤكَّد فلا يُمسّ — هو قرار المستخدم لا قرارنا.
     *
     * @param  Collection<int, AdInsightRow>  $rows
     */
    private function recordExternals(Collection $rows): int
    {
        $channels = AdChannel::get(['id', 'name']);
        $products = Product::get(['id', 'name']);
        $provider = $this->platform->provider()->name();
        $new = 0;

        $externals = $rows
            ->flatMap(fn (AdInsightRow $r) => [
                AdExternalMap::TYPE_CAMPAIGN.':'.$r->campaignId => [AdExternalMap::TYPE_CAMPAIGN, $r->campaignId, $r->campaignName],
                AdExternalMap::TYPE_ADSET.':'.$r->adsetId => [AdExternalMap::TYPE_ADSET, $r->adsetId, $r->adsetName],
            ]);

        foreach ($externals as [$type, $id, $name]) {
            if ($id === '') {
                continue;
            }

            $map = AdExternalMap::firstOrNew([
                'provider' => $provider,
                'external_type' => $type,
                'external_id' => $id,
            ]);

            $new += $map->exists ? 0 : 1;

            $map->external_name = $name;
            $map->last_seen_at = now();

            // الاقتراح يُحدَّث ما دام غير مربوط؛ وبعد الربط لا معنى له.
            if (! $map->isLinked()) {
                $map->suggested_ad_channel_id = $type === AdExternalMap::TYPE_CAMPAIGN
                    ? $this->suggest($name, $channels) : null;
                $map->suggested_product_id = $type === AdExternalMap::TYPE_ADSET
                    ? $this->suggest($name, $products) : null;
            }

            $map->save();
        }

        return $new;
    }

    /**
     * أقرب مرشَّح لاسمٍ خارجي — **اقتراحٌ للعرض لا ربطٌ تلقائي**.
     *
     * أطولُ اسمٍ يرد داخل الاسم الخارجي هو الفائز: «مكنسة» و«مكنسة كليكي» كلاهما
     * يرد في «مكنسة كليكي — نسخة»، والأطول أدقّ. والحدّ الأدنى ثلاثة أحرف يمنع
     * اسمًا قصيرًا من مطابقة كل شيء.
     *
     * @param  Collection<int, object>  $candidates
     */
    private function suggest(string $externalName, Collection $candidates): ?int
    {
        $needle = $this->normalize($externalName);

        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach ($candidates as $candidate) {
            $name = $this->normalize($candidate->name);

            if (mb_strlen($name) < 3 || ! str_contains($needle, $name)) {
                continue;
            }

            if (mb_strlen($name) > $bestLength) {
                $best = $candidate->id;
                $bestLength = mb_strlen($name);
            }
        }

        return $best;
    }

    /**
     * تطبيع عربي للمطابقة — نظير `norm()` في واجهة البحث عن الصنف.
     *
     * الأصناف مُدخَلة بصيغٍ مختلفة على المنصّة وفي الكتالوج: «الاذن» و«الأذن»،
     * «شواية» و«شوايه». بلا تطبيعٍ يسقط الاقتراح حيث يجب أن ينجح.
     */
    private function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $text);

        return trim(mb_strtolower($text));
    }

    /**
     * الربط المؤكَّد مفهرسًا بالمعرّف الخارجي.
     *
     * @return array<string, int>
     */
    private function linkedIds(string $type, string $column): array
    {
        return AdExternalMap::query()
            ->where('external_type', $type)
            ->whereNotNull($column)
            ->pluck($column, 'external_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
