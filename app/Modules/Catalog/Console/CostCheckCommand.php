<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * كشفُ الأصناف التي تُباع بلا تكلفة أو بلا سعر جملة — **قراءةٌ لا كتابة**.
 *
 * ## لماذا يهمّ
 *
 * صنفٌ بتكلفة صفر يظهر ربحُه **كامل سعر بيعه**. فيتصدّر «الأعلى ربحًا» في لوحة
 * قرار الصنف، ويُضخّم مجمل الربح في قائمة الأرباح والخسائر، ويُغري بشراء
 * المزيد منه. والرقم ليس مبالغةً بل اختراعٌ كامل.
 *
 * وصنفٌ بلا **سعر جملة** يُفسد ربح المسوّق: أساسُ شرائه يرتدّ إلى التكلفة،
 * والتكلفة أدنى من الجملة — فيظهر هامشُه أكبر ممّا هو، وتُحتسب عمولتُه عليه.
 *
 * ## ولماذا أمرٌ لا شاشة
 *
 * هذا فحصُ سلامةِ بياناتٍ يُشغَّل قبل قرارٍ كبير — قبل تصحيح عمولات مسوّق، أو
 * قبل قراءة قائمة أرباحٍ لأوّل مرّة. والشاشة تُقرأ حين يُفتَح إليها، والأمر
 * يُشغَّل حين يُحتاج.
 */
class CostCheckCommand extends Command
{
    protected $signature = 'catalog:cost-check
                            {--sold : الأصناف المُباعة فعلًا وحدها}
                            {--limit=100 : أقصى عدد صفوف تُعرض}';

    protected $description = 'كشف الأصناف بلا تكلفة أو بلا سعر جملة وأثرها على الأرباح (قراءة فقط)';

    public function handle(): int
    {
        $variants = ProductVariant::query()
            ->with('product:id,name,average_cost,cost_price,wholesale_price,status')
            // لا علاقةَ `orderItems` على المتغيّر، فالحصر باستعلامٍ فرعيّ مباشر.
            ->when($this->option('sold'), fn ($q) => $q->whereIn(
                'id',
                DB::table('order_items')->distinct()->pluck('variant_id')->filter(),
            ))
            ->get();

        if ($variants->isEmpty()) {
            $this->warn('لا أصناف في النطاق المطلوب.');

            return self::SUCCESS;
        }

        $rows = $variants->map(fn (ProductVariant $v) => $this->inspect($v));

        $noCost = $rows->where('cost', '<=', 0);
        $noWholesale = $rows->where('wholesale', '<=', 0);

        $this->line('');
        $this->table(['البيان', 'العدد'], [
            ['أصناف فُحصت', $rows->count()],
            ['**بلا تكلفة** (ربحها يظهر كامل سعر البيع)', $noCost->count()],
            ['**بلا سعر جملة** (يُفسد ربح المسوّق وعمولته)', $noWholesale->count()],
            ['سليمة', $rows->filter(fn ($r) => $r['cost'] > 0 && $r['wholesale'] > 0)->count()],
        ]);

        $broken = $rows->filter(fn ($r) => $r['cost'] <= 0 || $r['wholesale'] <= 0);

        if ($broken->isEmpty()) {
            $this->line('');
            $this->info('✓ كل صنفٍ له تكلفة وسعر جملة.');

            return self::SUCCESS;
        }

        $this->details($broken);

        $this->line('');
        $this->comment('تُصحَّح من: المنتجات ← تعديل المنتج ← التكلفة وسعر الجملة.');
        $this->comment('لم يتغيّر شيء — هذا الأمر يقرأ ولا يكتب.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function inspect(ProductVariant $variant): array
    {
        // احتياطٌ بترتيبه: قيمةُ المتغيّر ثم قيمةُ صنفه — كما يقرؤها حساب الربح.
        $cost = (float) $variant->average_cost > 0
            ? (float) $variant->average_cost
            : (float) ($variant->product?->average_cost ?? 0);

        if ($cost <= 0) {
            $cost = (float) ($variant->product?->cost_price ?? 0);
        }

        $wholesale = $variant->effectiveWholesalePrice();
        $retail = (float) ($variant->retail_price ?: $variant->product?->retail_price ?? 0);

        return [
            'name' => $variant->product?->name ?? $variant->sku,
            'sku' => $variant->sku,
            'retail' => $retail,
            'cost' => round($cost, 2),
            'wholesale' => round($wholesale, 2),
            // الربح المعروض اليوم مقابل ما هو مجهول: بلا تكلفة يظهر كلُّ السعر ربحًا.
            'fake_margin' => $cost <= 0 && $retail > 0 ? 100.0 : null,
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function details(Collection $rows): void
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->line('');
        $this->warn('أصنافٌ تحتاج تصحيحًا:');

        $this->table(
            ['الصنف', 'SKU', 'سعر البيع', 'التكلفة', 'سعر الجملة', 'الخلل'],
            $rows->sortByDesc('retail')->take($limit)->map(fn ($r) => [
                mb_substr($r['name'], 0, 30),
                $r['sku'],
                number_format($r['retail'], 2),
                $r['cost'] > 0 ? number_format($r['cost'], 2) : '— صفر',
                $r['wholesale'] > 0 ? number_format($r['wholesale'], 2) : '— صفر',
                $this->fault($r),
            ])->values()->all(),
        );

        if ($rows->count() > $limit) {
            $this->line('');
            $this->warn('عُرض '.$limit.' من '.$rows->count().' — استعمل --limit لعرض المزيد.');
        }
    }

    /** @param  array<string, mixed>  $row */
    private function fault(array $row): string
    {
        return match (true) {
            $row['cost'] <= 0 && $row['wholesale'] <= 0 => 'بلا تكلفة ولا جملة',
            $row['cost'] <= 0 => 'ربحه يظهر ١٠٠٪ — وهمي',
            default => 'ربح المسوّق مُضخَّم',
        };
    }
}
