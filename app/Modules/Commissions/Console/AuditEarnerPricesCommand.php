<?php

namespace App\Modules\Commissions\Console;

use App\Models\User;
use App\Modules\Catalog\Services\PriceListService;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * فحصُ أسعار شراء مسوّقٍ على بنوده — **قراءةٌ لا كتابة**.
 *
 * ## السؤال الذي يجيبه
 *
 * «هل كل بندٍ باعه فلانٌ جُمّد بسعر شرائه الصحيح؟» والصحيح هو **سعر قائمته
 * المخصّصة إن كان الصنف فيها، وإلّا سعر الجملة الفعّال** — وهو ترتيب
 * `PriceListService` نفسه، فلا يفترق الفحص عن المصدر.
 *
 * ## ولماذا لا يكفي `commissions:reprice-earner`
 *
 * ذاك يمرّ على البنود التي **عليها عمولةٌ حيّة** لهذا المسوّق وحدها. وبندٌ
 * عُكست عمولته أو أُلغيت أو لم تُستحقّ أصلًا يبقى خارجه — بينما لقطتُه
 * (`wholesale_price_snapshot`) تُغذّي عمودَي «ربح المسوّق» و«ربح توفير» في
 * التقرير. فالكشف قد يكون سليمًا والتقرير غير سليم.
 *
 * فهذا الأمر يمرّ على **كل بنود المسوّق**، ويفصل ما يُصلحه `reprice-earner`
 * عمّا يحتاج يدًا.
 *
 * ## وما لا يفعله
 *
 * لا يكتب شيئًا. أمرٌ يحرّك مستحقّات شخصٍ بعينه يُقرأ أوّلًا ويُقرّر بعده.
 */
class AuditEarnerPricesCommand extends Command
{
    protected $signature = 'commissions:audit-earner-prices
                            {user : بريد المسوّق أو معرّفه}
                            {--from= : من تاريخ (YYYY-MM-DD)}
                            {--to= : إلى تاريخ (YYYY-MM-DD)}
                            {--all : عرض كل البنود لا المختلف منها فقط}
                            {--order= : رقم طلبٍ أو رقم تتبّع — يُفكَّك سطرًا سطرًا بدل الفحص العامّ}';

    protected $description = 'فحص أسعار شراء مسوّق على بنوده وأثرها في الأرباح والعمولات (قراءة فقط)';

    /** حالاتٌ لا بيع فيها — نفس ما تستبعده تقارير المبيعات. */
    private const EXCLUDED_STATUSES = ['draft', 'new', 'cancelled'];

    public function handle(PriceListService $prices): int
    {
        $earner = $this->resolveEarner();

        if (! $earner) {
            return self::FAILURE;
        }

        $list = $prices->listFor($earner);

        $this->line('');
        $this->info("المسوّق: {$earner->name}  (#{$earner->id})");
        $this->line($list
            ? "قائمة الأسعار: {$list->name}"
            : 'قائمة الأسعار: لا قائمة مُسنَدة — المتوقَّع لكل صنف هو سعر الجملة.');

        if ($this->option('order')) {
            return $this->explainOrder($earner, $prices, $list);
        }

        $items = $this->items($earner);

        if ($items->isEmpty()) {
            $this->warn('لا بنود لهذا المسوّق في النطاق المطلوب.');

            return self::SUCCESS;
        }

        $listPrices = $list
            ? $prices->pricesForList($list, $items->pluck('variant_id')->filter()->unique()->values()->all())
            : collect();

        $rows = $items->map(fn (OrderItem $item) => $this->inspect($item, $listPrices));

        $this->summary($rows);

        $mismatched = $rows->where('differs', true);

        if ($mismatched->isEmpty()) {
            $this->line('');
            $this->info('✓ لا اختلاف — كل بندٍ مُجمَّد بسعر شرائه الصحيح.');

            return self::SUCCESS;
        }

        $this->details($this->option('all') ? $rows : $mismatched);
        $this->impact($mismatched);
        $this->nextSteps($mismatched);

        return self::SUCCESS;
    }

    // ————————————————————————— تفكيك فاتورة —————————————————————————

    /**
     * تفكيك فاتورةٍ واحدة سطرًا سطرًا — من سعر البيع إلى العمولة.
     *
     * الفحص العامّ يقول «هذا البند مختلف بكذا»، وهذا يقول **لماذا**: يعرض
     * الاشتقاق كاملًا فيُرى أين انكسر الرقم بدل تخمينه.
     *
     * والنسبة تُقرأ من الحركة نفسها لا من القاعدة الحالية: قاعدة العمولة قد
     * تكون تغيّرت بعد البيع، فقياسُ ما مضى عليها يخترع فرقًا ليس هناك.
     */
    private function explainOrder(User $earner, PriceListService $prices, $list): int
    {
        $key = (string) $this->option('order');

        $order = Order::with(['items.variant.product:id,name,wholesale_price'])
            ->where('affiliate_id', $earner->id)
            ->where(fn ($q) => $q->where('number', $key)->orWhere('tracking_number', $key))
            ->first();

        if (! $order) {
            $this->error("لا طلبَ لـ«{$earner->name}» برقم أو تتبّع «{$key}».");

            return self::FAILURE;
        }

        $listPrices = $list
            ? $prices->pricesForList($list, $order->items->pluck('variant_id')->filter()->unique()->values()->all())
            : collect();

        $this->line('');
        $this->info("الطلب: {$order->number}  ·  التتبّع: ".($order->tracking_number ?: '—'));
        $this->line('الحالة: '.$order->status.'  ·  الإجمالي: '.number_format((float) $order->total, 2)
            .'  ·  رسوم التوصيل: '.number_format((float) $order->shipping_total, 2));
        $this->line('');

        $rows = [];
        $deltaTotal = 0.0;

        foreach ($order->items as $item) {
            $row = $this->explainItem($item, $listPrices, $earner);
            $deltaTotal += $row['delta'];

            $rows[] = [
                mb_substr($row['name'], 0, 24),
                $row['source'],
                rtrim(rtrim(number_format($row['qty'], 2), '0'), '.'),
                number_format($row['price'], 2),
                number_format($row['used'], 2),
                number_format($row['expected'], 2),
                $row['rate'],
                number_format($row['now'], 2),
                number_format($row['should'], 2),
                number_format($row['delta'], 2),
            ];
        }

        $this->table(
            ['الصنف', 'المصدر', 'كمية', 'سعر البيع', 'شراؤه المُحتسَب', 'شراؤه المتوقَّع', 'النسبة', 'العمولة الآن', 'يجب أن تكون', 'الفرق'],
            $rows,
        );

        $this->line('');
        $this->line('صافي فرق العمولة على هذا الطلب: '.($deltaTotal > 0 ? '+' : '').number_format($deltaTotal, 2));
        $this->line('');
        $this->comment('العمولة = (سعر البيع − سعر شرائه) × الكمية × النسبة — بلا رسوم التوصيل.');
        $this->comment('لم يتغيّر شيء — هذا الأمر يقرأ ولا يكتب.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, float>  $listPrices
     * @return array<string, mixed>
     */
    private function explainItem(OrderItem $item, Collection $listPrices, User $earner): array
    {
        $inList = $listPrices->has($item->variant_id);

        $expected = round((float) ($inList
            ? $listPrices[$item->variant_id]
            : ($item->variant?->effectiveWholesalePrice() ?? 0)), 2);

        // المُستعمَل فعلًا لا اللقطة الخام — اللقطة صفرًا تعني ارتدادًا إلى
        // سعر جملة الصنف، وعرضُ الصفر هنا يُخفي الرقم الذي أنتج العمولة.
        $used = $this->effectiveCost($item);
        $qty = (float) $item->qty;
        $price = round((float) $item->unit_price, 2);

        $entry = CommissionEntry::where('order_item_id', $item->id)
            ->where('earner_id', $earner->id)
            ->where('earner_type', 'affiliate')
            ->where('entry_type', 'accrual')
            ->whereNotIn('state', ['reversed', 'cancelled'])
            ->first();

        // النسبة من الحركة نفسها: `1.0` تعني «الهامش كاملًا للمسوّق».
        $rate = $entry?->rate !== null ? (float) $entry->rate : 1.0;

        $now = $entry ? round((float) $entry->amount, 2) : 0.0;
        $should = round(max(0, ($price - $expected) * $qty) * $rate, 2);

        return [
            'name' => $item->variant?->product?->name ?? $item->variant?->sku ?? 'بند حرّ',
            'source' => $inList ? 'قائمته' : 'الجملة',
            'qty' => $qty,
            'price' => $price,
            'used' => $used,
            'expected' => $expected,
            'rate' => $entry === null ? 'لا حركة' : ($entry->rate === null ? 'مبلغ ثابت' : rtrim(rtrim(number_format($rate * 100, 2), '0'), '.').'%'),
            'now' => $now,
            'should' => $should,
            'delta' => round($should - $now, 2),
        ];
    }

    // ————————————————————————— الجمع —————————————————————————

    private function resolveEarner(): ?User
    {
        $key = (string) $this->argument('user');

        $earner = User::where('email', $key)
            ->orWhere('id', is_numeric($key) ? (int) $key : 0)
            ->first();

        if (! $earner) {
            $this->error("لا مستخدم بالبريد أو المعرّف «{$key}».");
        }

        return $earner;
    }

    /** @return Collection<int, OrderItem> */
    private function items(User $earner): Collection
    {
        return OrderItem::query()
            ->with(['variant.product:id,name,wholesale_price', 'order:id,number,created_at,status'])
            ->whereHas('order', function ($q) use ($earner) {
                $q->where('affiliate_id', $earner->id)
                    ->whereNull('deleted_at')
                    ->whereNotIn('status', self::EXCLUDED_STATUSES)
                    ->when($this->option('from'), fn ($o, $d) => $o->whereDate('created_at', '>=', $d))
                    ->when($this->option('to'), fn ($o, $d) => $o->whereDate('created_at', '<=', $d));
            })
            ->get();
    }

    /**
     * مقارنة بندٍ واحد: ما جُمّد عليه مقابل ما كان يجب.
     *
     * @param  Collection<int, float>  $listPrices
     * @return array<string, mixed>
     */
    private function inspect(OrderItem $item, Collection $listPrices): array
    {
        $inList = $listPrices->has($item->variant_id);

        $expected = round((float) ($inList
            ? $listPrices[$item->variant_id]
            : ($item->variant?->effectiveWholesalePrice() ?? 0)), 2);

        $snapshot = round((float) $item->wholesale_price_snapshot, 2);
        $used = $this->effectiveCost($item);
        $qty = (float) $item->qty;

        return [
            'item' => $item,
            'order' => $item->order?->number ?? '—',
            'product' => $item->variant?->product?->name ?? $item->variant?->sku ?? 'بند حرّ',
            'source' => $inList ? 'قائمته' : 'الجملة',
            'in_list' => $inList,
            'qty' => $qty,
            'snapshot' => $snapshot,
            'used' => $used,
            'expected' => $expected,
            // الفرق للوحدة، وأثرُه على الربحين مضروبًا بالكمية — كلُّه على
            // **المُستعمَل** لا على اللقطة الخام.
            'delta_unit' => round($expected - $used, 2),
            // سعرُ شراءٍ أعلى من الصحيح ⇒ ربحُ المسوّق ظهر أقلّ، وربح توفير أكثر.
            'affiliate_effect' => round(($used - $expected) * $qty, 2),
            'company_effect' => round(($expected - $used) * $qty, 2),
            'differs' => abs($expected - $used) >= 0.01,
        ];
    }

    /**
     * سعر الشراء الذي **حُسبت عليه العمولة فعلًا**.
     *
     * ليس اللقطة الخام: `CommissionService::itemCost()` يرتدّ حين تكون اللقطة
     * صفرًا إلى سعر جملة الصنف ثم إلى تكلفته. فبندٌ لقطتُه صفر لم تُحسب عمولتُه
     * على صفر — وقياسُ الفرق على الصفر يقلب إشارة الأثر ويجعل مسوّقًا يستحقّ
     * زيادةً يبدو مدينًا بها.
     *
     * ونفس الترتيب هنا وهناك عمدًا: أيّ افتراقٍ بينهما يجعل الفحص يقيس شيئًا
     * لا يفعله النظام.
     */
    private function effectiveCost(OrderItem $item): float
    {
        $wholesale = (float) $item->wholesale_price_snapshot > 0
            ? (float) $item->wholesale_price_snapshot
            : (float) ($item->variant?->effectiveWholesalePrice() ?? 0);

        if ($wholesale > 0) {
            return round($wholesale, 2);
        }

        return round((float) ($item->wholesale_cost_snapshot ?? $item->variant?->average_cost ?? 0), 2);
    }

    // ————————————————————————— العرض —————————————————————————

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function summary(Collection $rows): void
    {
        $this->line('');
        $this->table(['البيان', 'العدد'], [
            ['بنودٌ فُحصت', $rows->count()],
            ['مسعَّرة من قائمته', $rows->where('in_list', true)->count()],
            ['مسعَّرة بسعر الجملة', $rows->where('in_list', false)->count()],
            ['**مختلفة عن المتوقَّع**', $rows->where('differs', true)->count()],
            // لقطةٌ صفر لا تعني عمولةً على صفر — الحساب ارتدّ إلى سعر جملة
            // الصنف. لكنّها تُعدّ لأنها بيانٌ ناقص يستحقّ الإصلاح.
            ['بلقطةٍ صفرٍ (ارتدّ الحساب لسعر الجملة)', $rows->filter(fn ($r) => $r['snapshot'] <= 0)->count()],
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function details(Collection $rows): void
    {
        $this->line('');
        $this->warn('البنود المختلفة:');

        $this->table(
            // عمودان لسعر الشراء: اللقطة الخام و**ما حُسبت عليه العمولة فعلًا**.
            // إخفاءُ الثاني كان يُظهر صفرًا حيث ارتدّ الحساب إلى سعر الجملة،
            // فينقلب اتّجاه الأثر ويبدو المسوّق مدينًا وهو دائن.
            ['الطلب', 'الصنف', 'المصدر', 'كمية', 'اللقطة', 'المُستعمَل فعلًا', 'المتوقَّع', 'فرق الوحدة', 'أثره على ربح المسوّق'],
            $rows->sortByDesc(fn ($r) => abs($r['affiliate_effect']))->map(fn ($r) => [
                $r['order'],
                mb_substr($r['product'], 0, 26),
                $r['source'],
                rtrim(rtrim(number_format($r['qty'], 2), '0'), '.'),
                $r['snapshot'] > 0 ? number_format($r['snapshot'], 2) : '— صفر',
                number_format($r['used'], 2),
                number_format($r['expected'], 2),
                number_format($r['delta_unit'], 2),
                number_format($r['affiliate_effect'], 2),
            ])->values()->all(),
        );
    }

    /**
     * أثر الفروق على الأرقام التي يقرؤها المستخدم فعلًا.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function impact(Collection $rows): void
    {
        $affiliate = round((float) $rows->sum('affiliate_effect'), 2);
        $company = round((float) $rows->sum('company_effect'), 2);

        $this->line('');
        $this->info('أثر التصحيح على تقرير «المبيعات حسب المسوّقين»:');
        $this->table(['العمود', 'التغيّر'], [
            ['ربح المسوّق', $this->signed($affiliate)],
            ['ربح توفير', $this->signed($company)],
            // مجموعهما صفرٌ دائمًا: القسمة تتحرّك، لا الربح الكلّي — سعر البيع
            // والتكلفة الفعلية لم يمسّهما شيء.
            ['مجموعهما (يجب أن يكون صفرًا)', $this->signed(round($affiliate + $company, 2))],
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function nextSteps(Collection $rows): void
    {
        $itemIds = $rows->pluck('item.id')->all();

        $live = CommissionEntry::whereIn('order_item_id', $itemIds)
            ->where('entry_type', 'accrual')
            ->whereNotIn('state', ['reversed', 'cancelled', 'paid'])
            ->distinct()->pluck('order_item_id');

        $paid = CommissionEntry::whereIn('order_item_id', $itemIds)
            ->where('entry_type', 'accrual')
            ->where('state', 'paid')
            ->distinct()->pluck('order_item_id');

        $none = collect($itemIds)->diff($live)->diff($paid);

        $this->line('');
        $this->info('ما يُصلحه كلُّ مسار:');
        $this->table(['الحالة', 'بنود', 'الإجراء'], [
            [
                'عليها عمولة حيّة',
                $live->count(),
                'commissions:reprice-earner '.$this->argument('user').' --apply',
            ],
            [
                'عمولتها مدفوعة',
                $paid->count(),
                'يتركها الأمر — سند الصرف يحمل مبلغها. تُسوّى يدويًّا إن لزم.',
            ],
            [
                'بلا عمولة حيّة',
                $none->count(),
                'لا يمسّها `reprice-earner` — لقطتُها تبقى خاطئةً في التقرير.',
            ],
        ]);

        if ($none->isNotEmpty()) {
            $this->line('');
            $this->warn('انتبه: '.$none->count().' بندًا بلا عمولة حيّة ستبقى لقطتُها كما هي بعد التصحيح،');
            $this->warn('فيبقى عمودا «ربح المسوّق» و«ربح توفير» عليها بالقيمة القديمة. أرسل لي هذا المُخرَج لأعالجها.');
        }

        $this->line('');
        $this->comment('لم يتغيّر شيء — هذا الأمر يقرأ ولا يكتب.');
    }

    private function signed(float $value): string
    {
        return ($value > 0 ? '+' : '').number_format($value, 2);
    }
}
