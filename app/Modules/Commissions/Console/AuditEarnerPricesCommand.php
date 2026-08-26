<?php

namespace App\Modules\Commissions\Console;

use App\Models\User;
use App\Modules\Catalog\Services\PriceListService;
use App\Modules\Commissions\Models\CommissionEntry;
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
                            {--all : عرض كل البنود لا المختلف منها فقط}';

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

        $frozen = round((float) $item->wholesale_price_snapshot, 2);
        $qty = (float) $item->qty;

        return [
            'item' => $item,
            'order' => $item->order?->number ?? '—',
            'product' => $item->variant?->product?->name ?? $item->variant?->sku ?? 'بند حرّ',
            'source' => $inList ? 'قائمته' : 'الجملة',
            'in_list' => $inList,
            'qty' => $qty,
            'frozen' => $frozen,
            'expected' => $expected,
            // الفرق للوحدة، وأثرُه على الربحين مضروبًا بالكمية.
            'delta_unit' => round($expected - $frozen, 2),
            // لقطةٌ أعلى من الصحيح ⇒ ربحُ المسوّق ظهر أقلّ، وربح توفير أكثر.
            'affiliate_effect' => round(($frozen - $expected) * $qty, 2),
            'company_effect' => round(($expected - $frozen) * $qty, 2),
            'differs' => abs($expected - $frozen) >= 0.01,
        ];
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
            ['بلقطةٍ صفرٍ أو فارغة', $rows->filter(fn ($r) => $r['frozen'] <= 0)->count()],
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function details(Collection $rows): void
    {
        $this->line('');
        $this->warn('البنود المختلفة:');

        $this->table(
            ['الطلب', 'الصنف', 'المصدر', 'كمية', 'المُجمَّد', 'المتوقَّع', 'فرق الوحدة', 'أثره على ربح المسوّق'],
            $rows->sortByDesc(fn ($r) => abs($r['affiliate_effect']))->map(fn ($r) => [
                $r['order'],
                mb_substr($r['product'], 0, 28),
                $r['source'],
                rtrim(rtrim(number_format($r['qty'], 2), '0'), '.'),
                number_format($r['frozen'], 2),
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
