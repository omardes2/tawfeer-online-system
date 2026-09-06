<?php

namespace App\Modules\Purchasing\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * فحص تكلفة المقاسات التي وُزِّعت من صنفٍ مجرَّد — **قراءة فقط، لا يكتب شيئًا**.
 *
 * ## العطب الذي يبحث عنه
 *
 * حين تُوزَّع كمية الصنف على مقاساته، تُدخِل المصفوفةُ البضاعةَ للمقاسات بـ
 * `cost_price` المسجَّل على المتغيّر لا بتكلفة الشراء الفعلية من الفاتورة:
 *
 *     $this->inventory->adjustIn($variant, $warehouse, $delta, $variant->cost_price ?: null, ...)
 *
 * فإن كان `cost_price` صفرًا أو قيمةً قديمة، دخلت البضاعة بتكلفةٍ لا تساوي ما
 * دُفع فيها. والأثر لا يظهر في الكميات — تبقى صحيحة — بل في **الربح**: كل بيعة
 * من هذا المقاس تُحتسب على تكلفةٍ غير حقيقية.
 *
 * ## وما يقارنه
 *
 * متوسط تكلفة المقاس الآن ⟵ تكلفة الشراء الواصلة لآخر فاتورة أدخلت الصنف
 * (المجرَّد أو المقاس). والفرق يُضرَب في الرصيد القائم ليُقرأ بالشيكل لا
 * بالنسبة: فرقُ قرشين على ٦٬٠٠٠ قطعة ليس فرق قرشين.
 */
class AuditVariantSplitCostsCommand extends Command
{
    protected $signature = 'purchasing:audit-variant-costs
                            {product? : اسم المنتج أو معرّفه أو SKU — بدونه كل المنتجات الموزَّعة}
                            {--all : اعرض المطابق أيضًا لا المخالف وحده}';

    protected $description = 'مقارنة تكلفة المقاسات الموزَّعة بتكلفة الشراء الفعلية (قراءة فقط)';

    public function handle(): int
    {
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();

        if (! $warehouse) {
            $this->error('لا مستودع.');

            return self::FAILURE;
        }

        $products = $this->products();

        if ($products->isEmpty()) {
            $this->error('لا منتج مطابق.');

            return self::FAILURE;
        }

        $rows = [];
        $exposure = 0.0;

        foreach ($products as $product) {
            foreach ($this->auditProduct($product, $warehouse) as $row) {
                if (! $row['mismatch'] && ! $this->option('all')) {
                    continue;
                }

                $exposure += $row['gap_value'];
                $rows[] = [
                    $product->name,
                    $row['label'],
                    $this->num($row['stock']),
                    number_format($row['now'], 2),
                    number_format($row['purchase'], 2),
                    number_format($row['now'] - $row['purchase'], 2),
                    number_format($row['gap_value'], 2),
                ];
            }
        }

        if ($rows === []) {
            $this->info('لا فروق — تكلفة كل مقاس توافق تكلفة شرائه.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->table(
            ['المنتج', 'المقاس', 'الرصيد', 'تكلفته الآن', 'تكلفة الشراء', 'الفرق للقطعة', 'أثره بالشيكل'],
            $rows,
        );

        $this->line('');
        $this->warn('صافي الفرق على المخزون القائم: '.number_format($exposure, 2));
        $this->line('موجبٌ يعني أن مخزونك مُقيَّم بأعلى من كلفته (ربحك المعلن أقلّ من حقيقته)،');
        $this->line('وسالبٌ يعني العكس — مُقيَّم بأدنى، فربح كل بيعة معلَنٌ أعلى من حقيقته.');
        $this->line('');
        $this->info('قراءة فقط — لم يُكتب شيء.');

        return self::SUCCESS;
    }

    /**
     * مقاسات المنتج التي تحمل رصيدًا، مع تكلفتها الآن وتكلفة شرائها.
     *
     * @return array<int, array<string, mixed>>
     */
    private function auditProduct(Product $product, Warehouse $warehouse): array
    {
        $variants = $product->variants()->with('attributeValues')->get();
        $sized = $variants->filter(fn (ProductVariant $v) => $v->attributeValues->isNotEmpty());

        // لا مقاسات ⇒ لا توزيع ⇒ لا شيء يُفحص.
        if ($sized->isEmpty()) {
            return [];
        }

        // تكلفة الشراء المرجعية: آخر بندٍ أدخل هذا المنتج — بالمقاس أو بالمجرَّد.
        // المجرَّد مقصود: البضاعة الموزَّعة دخلت عليه، وتكلفتها هي المرجع.
        $purchase = $this->lastPurchaseCost($variants->pluck('id')->all());

        if ($purchase === null) {
            return [];
        }

        $stocks = InventoryStock::whereIn('variant_id', $sized->pluck('id'))
            ->where('warehouse_id', $warehouse->id)
            ->get()->keyBy('variant_id');

        $rows = [];

        foreach ($sized as $variant) {
            $stock = $stocks->get($variant->id);
            $onHand = round((float) ($stock?->on_hand ?? 0), 3);

            if ($onHand <= 0) {
                continue; // بلا رصيد ⇒ لا أثر على التقييم ولا على ربحٍ قادم.
            }

            $now = round((float) ($stock?->average_cost ?? 0), 4);
            $gap = round($now - $purchase, 4);

            $rows[] = [
                'label' => $variant->optionLabel() ?: ($variant->sku ?: '#'.$variant->id),
                'stock' => $onHand,
                'now' => $now,
                'purchase' => $purchase,
                'gap_value' => round($gap * $onHand, 2),
                // القرش فرقٌ حقيقي حين يُضرب في آلاف القطع، لكنّ الفرق دون نصف
                // قرشٍ للقطعة تقريبُ حسابٍ لا خطأ تكلفة.
                'mismatch' => abs($gap) >= 0.005,
            ];
        }

        return $rows;
    }

    /**
     * تكلفة الوحدة الواصلة في آخر بند شراءٍ لهذه المتغيّرات.
     *
     * الواصلة لا سعر المورد: هي ما يدخل به المخزون فعلًا (`stockUnitCost`)،
     * ومقارنة متوسط التكلفة بغيرها تُنتج فرقًا وهميًّا في كل فاتورة استيراد.
     *
     * @param  array<int, int>  $variantIds
     */
    private function lastPurchaseCost(array $variantIds): ?float
    {
        $item = PurchaseInvoiceItem::query()
            ->whereIn('variant_id', $variantIds)
            ->whereHas('invoice', fn ($q) => $q->where('status', 'posted'))
            ->orderByDesc('id')
            ->first();

        if (! $item) {
            return null;
        }

        $landed = (float) $item->landed_unit_cost;

        return round($landed > 0 ? $landed : (float) $item->unit_cost, 4);
    }

    /** @return Collection<int, Product> */
    private function products(): Collection
    {
        $key = $this->argument('product');

        if ($key === null) {
            return Product::query()->whereHas('variants.attributeValues')->orderBy('name')->get();
        }

        return Product::query()
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', (int) $key))
            ->orWhere('sku', $key)
            ->orWhere('name', 'like', '%'.$key.'%')
            ->orderBy('name')->get();
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
