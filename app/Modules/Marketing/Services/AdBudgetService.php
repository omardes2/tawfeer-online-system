<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Marketing\Models\OperatingDailyCost;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * حساب «الميزانية اليومية»: ربح كل صنف على كل صفحة، مقابل ما صُرف عليه إعلانيًّا.
 *
 * ثلاثة قرارات تحكم هذا الملف:
 *
 * 1. **الأساس على الطلبات المُدخَلة لا المسلَّمة.** نسبة عدم التسليم لا تتجاوز
 *    ~5%، فانتظارُ التسليم يؤخّر القرار أسبوعًا مقابل دقّةٍ لا تُذكر. والـ5%
 *    تُلتقط رغم ذلك: تُستبعَد حالة «مُرتجَع»، وتُخصَم `returned_qty` بالتناسب —
 *    فيخرج المرتجع من التقرير **حين يُسجَّل** بلا انتظار.
 *
 * 2. **وحدة القرار (صنف × قناة) لا الصنف وحده.** وهي البنية نفسها في مدير
 *    إعلانات Meta: الحملة صفحة، والمجموعة الإعلانية صنفٌ بميزانيته.
 *
 * 3. **المصروف التشغيلي الثابت لا يدخل صفّ الصنف.** انظر `OperatingDailyCost`.
 *
 * وأساس الربح هنا **ليس** أساس `BusinessReportController`: ذاك يُبقي المرتجع
 * مبيعًا (طلبًا للاتّساق مع ما اعتاده المستخدم في التقارير الثلاثة)، وهذا
 * يخصمه. الفارق مقصود ومحصورٌ في هذه الصفحة.
 */
class AdBudgetService
{
    /** حالات لا مبيعَ فيها — و«مُرتجَع» منها هنا وحدها دون التقارير الأخرى. */
    private const EXCLUDED_STATUSES = ['draft', 'new', 'cancelled', 'returned'];

    /**
     * حصّة البند الباقية بعد المرتجع الجزئي.
     *
     * الضرب في `1.0` ليس زينة: SQLite يقسم الأعداد الصحيحة قسمةً صحيحة، فكانت
     * `3 / 4` تعطي صفرًا وتمحو البند كلَّه بدل أن تخصم ربعه.
     */
    private const NET_RATIO = '(CASE WHEN order_items.qty > 0 '
        .'THEN ((order_items.qty - COALESCE(order_items.returned_qty, 0)) * 1.0) / order_items.qty ELSE 0 END)';

    private const SALE_SQL = '((order_items.qty * order_items.unit_price - order_items.discount) * '.self::NET_RATIO.')';

    private const COST_SQL = '((order_items.qty * COALESCE(order_items.wholesale_cost_snapshot, product_variants.average_cost, 0)) * '.self::NET_RATIO.')';

    /**
     * تقرير يومٍ واحد: صفّ لكل (صنف × قناة)، وحكمٌ محسوبٌ على نافذةٍ تنتهي به.
     *
     * @return array{
     *     day: Carbon, window_from: Carbon, window_days: int,
     *     rows: Collection<int, array<string, mixed>>,
     *     channels: Collection<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     missing_days: array<int, array<int, string>>,
     *     thresholds: array<string, float|int>
     * }
     */
    public function report(Carbon $day, ?int $channelId = null): array
    {
        $thresholds = $this->thresholds();
        $windowDays = (int) $thresholds['window_days'];

        $day = $day->copy()->startOfDay();
        $windowFrom = $day->copy()->subDays($windowDays - 1);

        $daySales = $this->sales($day, $day->copy()->endOfDay(), $channelId);
        $winSales = $this->sales($windowFrom, $day->copy()->endOfDay(), $channelId);
        $daySpend = $this->spend($day, $day, $channelId);
        $winSpend = $this->spend($windowFrom, $day, $channelId);

        $missing = $this->missingDays($windowFrom, $day, $channelId);

        $channels = AdChannel::ordered()->get()->keyBy('id');
        $keys = $daySales->keys()->merge($daySpend->keys())->unique()->values();
        $productNames = Product::whereIn('id', $keys->map(fn ($k) => (int) explode(':', $k)[1]))
            ->pluck('name', 'id');

        $rows = $keys
            ->map(function (string $key) use ($daySales, $daySpend, $winSales, $winSpend, $missing, $channels, $productNames, $thresholds) {
                [$cid, $pid] = array_map('intval', explode(':', $key));

                $sale = $daySales->get($key, $this->zeroSale());
                $spend = $daySpend->get($key, $this->zeroSpend());
                $window = $this->combine($winSales->get($key, $this->zeroSale()), $winSpend->get($key, $this->zeroSpend()));
                $blockedDays = $missing[$cid] ?? [];

                return [
                    'channel_id' => $cid,
                    'channel' => $cid ? ($channels[$cid]->name ?? '#'.$cid) : __('بلا قناة'),
                    'unassigned' => $cid === 0,
                    'product_id' => $pid,
                    'product' => $productNames[$pid] ?? __('صنف محذوف'),
                    'orders' => $sale['orders'],
                    'sales' => $sale['sales'],
                    'profit_before_ads' => $sale['profit'],
                    'spend_usd' => $spend['usd'],
                    'spend' => $spend['local'],
                    'conversations' => $spend['conversations'],
                    'has_spend_row' => $spend['exists'],
                    'spend_id' => $spend['id'],
                    'conflict' => $spend['conflict'],
                    'platform_usd' => $spend['platform_usd'],
                    'platform_conversations' => $spend['platform_conversations'],
                    'net_profit' => round($sale['profit'] - $spend['local'], 2),
                    'cpa' => $sale['orders'] > 0 ? round($spend['local'] / $sale['orders'], 2) : null,
                    'window' => $window,
                    'verdict' => $this->verdict($window, $blockedDays, $thresholds, $cid),
                ];
            })
            ->sortByDesc('spend')
            ->sortBy(fn ($r) => $r['unassigned'] ? 1 : 0)
            ->values();

        return [
            'day' => $day,
            'window_from' => $windowFrom,
            'window_days' => $windowDays,
            'rows' => $rows,
            'channels' => $this->channelTotals($rows, $channels),
            'totals' => $this->dayTotals($rows, $day, $channelId),
            'missing_days' => $missing,
            'thresholds' => $thresholds,
        ];
    }

    /** العتبات وسعر الصرف من الإعدادات — أرقام عملٍ لا ثوابت كود. */
    public function thresholds(): array
    {
        return [
            'usd_rate' => (float) Settings::get('ads.usd_rate', 3.7),
            'window_days' => max(1, (int) Settings::get('ads.window_days', 3)),
            'min_orders' => max(1, (int) Settings::get('ads.min_orders', 10)),
            'increase_below' => (float) Settings::get('ads.cpa_increase_below', 30),
            'hold_below' => (float) Settings::get('ads.cpa_hold_below', 45),
            'reduce_below' => (float) Settings::get('ads.cpa_reduce_below', 60),
        ];
    }

    /**
     * الحكم على النافذة.
     *
     * الترتيب مقصود: الاستثناءان الفوريّان أولًا (صرفٌ بلا محادثة، وصرفٌ ثقيل
     * بلا طلب) لأنهما لا ينتظران اكتمال العيّنة، ثم يُحجَب الحكم إن نقص إدخال
     * يومٍ من النافذة — فالصرف الناقص يجعل الصنف يبدو أربح ممّا هو.
     *
     * @param  array<int, string>  $blockedDays
     */
    private function verdict(array $w, array $blockedDays, array $t, int $channelId): array
    {
        if ($channelId === 0) {
            return $this->tone('none', __('لا حكم'), 'gray', __('طلبات بلا قناة — لا صرف إعلاني يُنسب إليها.'));
        }

        if ($w['spend'] <= 0 && $w['orders'] === 0) {
            return $this->tone('none', __('لا حكم'), 'gray', __('لا صرف ولا مبيعات في النافذة.'));
        }

        if ($w['spend'] > 0 && $w['conversations'] === 0) {
            return $this->tone('stop', __('أوقف'), 'red', __('صُرف :s ولم تُبدأ أي محادثة.', ['s' => $this->fmt($w['spend'])]));
        }

        if ($w['orders'] === 0 && $w['spend'] >= 3 * $t['hold_below']) {
            return $this->tone('stop', __('أوقف'), 'red', __('صُرف :s بلا طلب واحد — أكثر من ثلاثة أضعاف تكلفة الطلب المستهدفة.', ['s' => $this->fmt($w['spend'])]));
        }

        if ($blockedDays !== []) {
            return $this->tone('blocked', __('بانتظار الإدخال'), 'amber', __('لم يُدخل صرف :n من أيام النافذة: :d', [
                'n' => count($blockedDays), 'd' => implode('، ', $blockedDays),
            ]));
        }

        if ($w['spend'] <= 0) {
            return $this->tone('blocked', __('بانتظار الإدخال'), 'amber', __('مبيعات بلا صرف مُدخَل — أدخل قيمة الإعلان أولًا.'));
        }

        if ($w['orders'] < $t['min_orders']) {
            return $this->tone('insufficient', __('بيانات غير كافية'), 'gray', __(':o من :m طلبات — الحكم دون ذلك ضجيجٌ لا دلالة.', [
                'o' => $w['orders'], 'm' => (int) $t['min_orders'],
            ]));
        }

        $cpa = $w['cpa'];
        $reason = __('تكلفة الطلب :c على مدى :n أيام.', ['c' => $this->fmt($cpa), 'n' => (int) $t['window_days']]);

        return match (true) {
            $cpa < $t['increase_below'] => $this->tone('increase', __('زد'), 'green', $reason),
            $cpa < $t['hold_below'] => $this->tone('hold', __('ثبّت'), 'blue', $reason),
            $cpa < $t['reduce_below'] => $this->tone('reduce', __('أنقص'), 'amber', $reason),
            default => $this->tone('stop', __('أوقف'), 'red', $reason),
        };
    }

    private function tone(string $code, string $label, string $tone, string $reason): array
    {
        return ['code' => $code, 'label' => $label, 'tone' => $tone, 'reason' => $reason];
    }

    private function fmt(float $amount): string
    {
        return number_format($amount, 2).' '.Settings::get('store.currency_symbol', '₪');
    }

    /**
     * مبيعات الفترة مجمَّعةً بـ(قناة، صنف).
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function sales(Carbon $from, Carbon $to, ?int $channelId): Collection
    {
        return $this->soldGoods($from, $to, $channelId)
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNotNull('products.id')
            ->groupBy('orders.ad_channel_id', 'products.id')
            ->selectRaw('orders.ad_channel_id as cid, products.id as pid, '
                .'COUNT(DISTINCT orders.id) as orders_count, '
                .'SUM('.self::SALE_SQL.') as sale_total, '
                .'SUM('.self::COST_SQL.') as cost_total')
            ->get()
            ->mapWithKeys(fn ($r) => [((int) $r->cid).':'.((int) $r->pid) => [
                'orders' => (int) $r->orders_count,
                'sales' => round((float) $r->sale_total, 2),
                'profit' => round((float) $r->sale_total - (float) $r->cost_total, 2),
            ]]);
    }

    /**
     * الصرف المُدخَل للفترة مجمَّعًا بـ(قناة، صنف).
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function spend(Carbon $from, Carbon $to, ?int $channelId): Collection
    {
        return AdDailySpend::query()
            ->whereBetween('spend_date', $this->dateSpan($from, $to))
            ->when($channelId, fn ($q) => $q->where('ad_channel_id', $channelId))
            ->groupBy('ad_channel_id', 'product_id')
            ->selectRaw('ad_channel_id as cid, product_id as pid, '
                .'SUM(amount_usd) as usd, SUM(amount_usd * fx_rate) as local, '
                .'SUM(conversations) as conversations, MIN(id) as row_id, '
                // قيمة المنصّة بجانب اليدوية — تُعرَض عند اختلافهما ولا تدهسها.
                .'SUM(COALESCE(synced_amount_usd, amount_usd)) as synced_usd, '
                .'SUM(COALESCE(synced_conversations, conversations)) as synced_conv, '
                ."MAX(CASE WHEN source = 'manual' AND synced_at IS NOT NULL THEN 1 ELSE 0 END) as manual_synced")
            ->get()
            ->mapWithKeys(function ($r) {
                $usd = round((float) $r->usd, 2);
                $syncedUsd = round((float) $r->synced_usd, 2);

                return [((int) $r->cid).':'.((int) $r->pid) => [
                    'usd' => $usd,
                    'local' => round((float) $r->local, 2),
                    'conversations' => (int) $r->conversations,
                    'exists' => true,
                    // صفٌّ واحد لكل مفتاح في اليوم الواحد (الفهرس الفريد)، فالأدنى هو هو.
                    'id' => (int) $r->row_id,
                    'platform_usd' => $syncedUsd,
                    'platform_conversations' => (int) $r->synced_conv,
                    'conflict' => (int) $r->manual_synced === 1
                        && (abs($usd - $syncedUsd) >= 0.01 || (int) $r->conversations !== (int) $r->synced_conv),
                ]];
            });
    }

    /**
     * ثغرات الإدخال داخل النافذة، لكل قناة.
     *
     * القياس على مستوى القناة لا الصنف: اليوم الذي لم يُنسخ من لوحة Meta تغيب
     * كل أصنافه معًا، أمّا صنفٌ بلا صفٍّ في يومٍ مُدخَل فمعناه أنه لم يُعلَن.
     *
     * والمقصود الثغرة **الداخلية** وحدها — يومٌ بين أول إدخالٍ وآخره. أمّا أيام
     * الطرفين فقد تسبق إطلاق الحملة أو تلي إيقافها، ووسمُها كان يُبقي كل حملة
     * جديدة «بانتظار الإدخال» طوال أيام نافذتها الأولى بلا سبب.
     *
     * @return array<int, array<int, string>>
     */
    private function missingDays(Carbon $from, Carbon $to, ?int $channelId): array
    {
        return AdDailySpend::query()
            ->whereBetween('spend_date', $this->dateSpan($from, $to))
            ->when($channelId, fn ($q) => $q->where('ad_channel_id', $channelId))
            ->distinct()
            ->get(['ad_channel_id', 'spend_date'])
            ->groupBy('ad_channel_id')
            ->map(function ($rows) use ($from, $to) {
                $days = $rows->map(fn ($r) => $r->spend_date->toDateString())->sort()->values();
                $first = $days->first();
                $last = $days->last();
                $missing = [];

                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    $date = $d->toDateString();
                    if ($date > $first && $date < $last && ! $days->contains($date)) {
                        $missing[] = $date;
                    }
                }

                return $missing;
            })
            ->filter(fn (array $m) => $m !== [])
            ->all();
    }

    /**
     * طرفا مدًى يغطّيان اليومين كاملين.
     *
     * `spend_date` عمود تاريخ، لكن Laravel يكتب فيه بصيغة الوقت الكاملة — فمقارنته
     * بـ`'2026-08-15'` وحدها تُسقط صفَّ ذلك اليوم نفسه على SQLite. المدى الكامل
     * يتصرّف بالطريقة نفسها على المحرّكين.
     *
     * @return array<int, string>
     */
    private function dateSpan(Carbon $from, Carbon $to): array
    {
        return [
            $from->copy()->startOfDay()->format('Y-m-d H:i:s'),
            $to->copy()->endOfDay()->format('Y-m-d H:i:s'),
        ];
    }

    /** بنود الطلبات المحتسَبة: مؤكّدة فأكثر، غير ملغاة ولا مرتجعة. */
    private function soldGoods(Carbon $from, Carbon $to, ?int $channelId): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$from, $to])
            ->when($channelId, fn ($q) => $q->where('orders.ad_channel_id', $channelId));
    }

    /** دمج مبيعات النافذة وصرفها في أرقام الحكم. */
    private function combine(array $sale, array $spend): array
    {
        return [
            'orders' => $sale['orders'],
            'sales' => $sale['sales'],
            'profit' => $sale['profit'],
            'spend' => $spend['local'],
            'conversations' => $spend['conversations'],
            'net_profit' => round($sale['profit'] - $spend['local'], 2),
            'cpa' => $sale['orders'] > 0 ? round($spend['local'] / $sale['orders'], 2) : 0.0,
            'conversion' => $spend['conversations'] > 0 ? round($sale['orders'] / $spend['conversations'] * 100, 1) : null,
        ];
    }

    /** ملخّص كل قناة عبر أصنافها — أيّ صفحة تستحقّ التوسّع. */
    private function channelTotals(Collection $rows, Collection $channels): Collection
    {
        return $rows
            ->groupBy('channel_id')
            ->map(fn (Collection $g, $cid) => [
                'channel_id' => (int) $cid,
                'channel' => (int) $cid ? ($channels[(int) $cid]->name ?? '#'.$cid) : __('بلا قناة'),
                'orders' => $g->sum('orders'),
                'sales' => round($g->sum('sales'), 2),
                'profit_before_ads' => round($g->sum('profit_before_ads'), 2),
                'spend' => round($g->sum('spend'), 2),
                'conversations' => $g->sum('conversations'),
                'net_profit' => round($g->sum('net_profit'), 2),
            ])
            ->sortByDesc('net_profit')
            ->values();
    }

    /**
     * بطاقة اليوم — وهنا وحدها يدخل المصروف التشغيلي الثابت.
     *
     * عدد الطلبات محسوبٌ مستقلًّا لا بجمع الصفوف: الطلب الذي يضمّ صنفين معدودٌ
     * في صفّيهما.
     */
    private function dayTotals(Collection $rows, Carbon $day, ?int $channelId): array
    {
        $orders = (int) $this->soldGoods($day, $day->copy()->endOfDay(), $channelId)
            ->distinct()->count('orders.id');

        $profit = round($rows->sum('profit_before_ads'), 2);
        $spend = round($rows->sum('spend'), 2);
        $fixed = OperatingDailyCost::amountFor($day);

        return [
            'orders' => $orders,
            'sales' => round($rows->sum('sales'), 2),
            'profit_before_ads' => $profit,
            'spend' => $spend,
            'spend_usd' => round($rows->sum('spend_usd'), 2),
            'conversations' => $rows->sum('conversations'),
            'profit_after_ads' => round($profit - $spend, 2),
            'fixed_cost' => $fixed,
            // الربح التشغيلي: ما يبقى بعد الإعلان والمصروف الثابت معًا.
            'operating_profit' => round($profit - $spend - $fixed, 2),
        ];
    }

    private function zeroSale(): array
    {
        return ['orders' => 0, 'sales' => 0.0, 'profit' => 0.0];
    }

    private function zeroSpend(): array
    {
        return [
            'usd' => 0.0, 'local' => 0.0, 'conversations' => 0, 'exists' => false, 'id' => null,
            'platform_usd' => 0.0, 'platform_conversations' => 0, 'conflict' => false,
        ];
    }
}
