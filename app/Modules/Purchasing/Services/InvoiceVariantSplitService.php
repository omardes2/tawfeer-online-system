<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\AuditLog;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * توزيع بند فاتورة شراء من «الصنف المجرَّد» على مقاساته — **بلا لمس المخزون**.
 *
 * ## العطب الذي يعالجه
 *
 * تُدخَل الفاتورة على منتجٍ بلا مقاسات، ثم تُنشأ له ألوان/مقاسات. ومصفوفة
 * المتغيّرات **توزّع ولا تضيف**: تُصفّر رصيد الصنف المجرَّد وتنقله للمقاسات.
 * فيبقى بند الفاتورة مشيرًا إلى متغيّرٍ رصيدُه صفر — والمخزون سليم، لكن:
 *
 * 1. تعديل الفاتورة يصير مستحيلًا: التعديل يسحب البضاعة أولًا ثم يُعيدها،
 *    ولا شيء ليُسحَب من المجرَّد.
 * 2. تقارير المشتريات بالمقاس تُظهر الكمية تحت صنفٍ عارٍ لا مقاس له.
 *
 * ## ولماذا لا يُلمَس المخزون
 *
 * الكمية **موزَّعة أصلًا وصحيحة** — المستخدم وزّعها بيده حين أنشأ المقاسات.
 * فأي حركة مخزون هنا تُضاعف ما هو قائم. هذه العملية **وسمٌ محاسبي لا حركة**:
 * تُصحّح إلى أيّ متغيّرٍ يشير سطرُ الفاتورة، ولا تُحرّك رصيدًا ولا تكلفة.
 *
 * ## والمال لا يتغيّر
 *
 * مجموع الأسطر الجديدة يساوي السطر الأصلي **بالقرش** — كمّيةً وقيمةً وضريبةً
 * وتكلفةً واصلة. فقيدُ اليومية وإجماليّات الفاتورة تبقى صحيحة بلا إعادة ترحيل،
 * ولا فرقَ يظهر في ميزان المراجعة. البواقي تُسنَد لأكبر سطر لا تُهمَل.
 *
 * ## يقرأ ولا يكتب افتراضيًّا
 *
 * `plan()` تعرض، و`apply()` وحدها تكتب — وداخل معاملة واحدة.
 */
class InvoiceVariantSplitService
{
    /**
     * خطّة التوزيع: بندٌ لكل صنفٍ مجرَّد في الفاتورة، مع أسطره المقترحة.
     *
     * الاقتراح الافتراضي يُبنى على **الرصيد الحالي للمقاسات** — لأنه هو نفسه
     * التوزيع الذي أدخله المستخدم في مصفوفة المتغيّرات. وحين يخالف مجموعُه كميةَ
     * البند (بيعٌ حدث بعد التوزيع مثلًا) يُوزَّع بالنسبة ويُرفَع العلَم `exact`
     * منخفضًا، فيراجع المستخدم بدل أن يُمرَّر رقمٌ مظنون على أنه محسوب.
     *
     * @param  array<int, array<int, float>>  $overrides  توزيعٌ صريح: [itemId => [variantId => qty]]
     * @return array<int, array<string, mixed>>
     */
    public function plan(PurchaseInvoice $invoice, array $overrides = []): array
    {
        $warehouse = $this->defaultWarehouse();
        $plan = [];

        foreach ($invoice->items()->with('variant.product', 'variant.attributeValues')->get() as $item) {
            $variant = $item->variant;

            if (! $variant || (float) $item->qty <= 0) {
                continue;
            }

            $siblings = $this->sizedSiblings($variant);

            // ليس مجرَّدًا: إمّا يحمل مقاسًا بنفسه، أو منتجُه بلا مقاسات أصلًا.
            if ($variant->attributeValues->isNotEmpty() || $siblings->isEmpty()) {
                continue;
            }

            $plan[] = $this->planItem($item, $variant, $siblings, $warehouse, $overrides[$item->id] ?? null);
        }

        return $plan;
    }

    /**
     * ينفّذ الخطّة: يُحوّل كل بندٍ مجرَّد إلى أسطرٍ بمقاساتها.
     *
     * السطر الأصلي يُعاد استعماله لأول مقاس ثم تُضاف البقيّة، فلا يُحذف صفٌّ
     * ولا يُنشأ معرّفٌ بلا داعٍ.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array{items: int, rows: int}
     */
    public function apply(PurchaseInvoice $invoice, array $plan, ?User $actor = null): array
    {
        $applicable = array_values(array_filter($plan, fn (array $p) => $p['rows'] !== []));

        if ($applicable === []) {
            return ['items' => 0, 'rows' => 0];
        }

        return DB::transaction(function () use ($invoice, $applicable, $actor) {
            $rows = 0;

            foreach ($applicable as $entry) {
                /** @var PurchaseInvoiceItem $item */
                $item = $entry['item'];
                $before = $item->only(['variant_id', 'qty', 'line_total', 'tax_amount', 'landed_line_total']);

                foreach ($entry['rows'] as $index => $row) {
                    $attributes = [
                        'variant_id' => $row['variant']->id,
                        'qty' => $row['qty'],
                        'line_total' => $row['line_total'],
                        'tax_amount' => $row['tax_amount'],
                        'landed_line_total' => $row['landed_line_total'],
                    ];

                    if ($index === 0) {
                        $item->update($attributes);
                    } else {
                        // البقيّة نسخةٌ من الأصل: سعر الوحدة والتكلفة الواصلة
                        // ونسبة الضريبة والوصف لا تتغيّر بتغيّر المقاس.
                        PurchaseInvoiceItem::create([
                            'purchase_invoice_id' => $invoice->id,
                            'description' => $item->description,
                            'new_product_name' => $item->new_product_name,
                            'new_product_sell_price' => $item->new_product_sell_price,
                            'unit_price_foreign' => $item->unit_price_foreign,
                            'cbm_per_unit' => $item->cbm_per_unit,
                            'unit_cost' => $item->unit_cost,
                            'landed_unit_cost' => $item->landed_unit_cost,
                            'landed_is_manual' => $item->landed_is_manual,
                            'tax_rate' => $item->tax_rate,
                            ...$attributes,
                        ]);
                    }

                    $rows++;
                }

                $this->log($invoice, $item, $before, $entry, $actor);
            }

            return ['items' => count($applicable), 'rows' => $rows];
        });
    }

    /**
     * مقاسات المنتج نفسه — المتغيّرات التي تحمل قيم خصائص.
     *
     * @return Collection<int, ProductVariant>
     */
    private function sizedSiblings(ProductVariant $variant): Collection
    {
        return ProductVariant::query()
            ->where('product_id', $variant->product_id)
            ->whereKeyNot($variant->id)
            ->whereHas('attributeValues')
            ->with(['attributeValues', 'product:id,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, ProductVariant>  $siblings
     * @param  array<int, float>|null  $override
     * @return array<string, mixed>
     */
    private function planItem(
        PurchaseInvoiceItem $item,
        ProductVariant $bare,
        Collection $siblings,
        ?Warehouse $warehouse,
        ?array $override,
    ): array {
        $qty = round((float) $item->qty, 3);
        $stocks = $this->stocks($siblings, $warehouse);

        [$quantities, $exact, $note] = $override !== null
            ? $this->fromOverride($override, $siblings, $qty)
            : $this->fromStock($siblings, $stocks, $qty);

        $rows = [];

        if ($quantities !== []) {
            $weights = array_values($quantities);
            $lineTotals = $this->allocate((float) $item->line_total, $weights);
            $taxes = $this->allocate((float) $item->tax_amount, $weights);
            $landed = $this->allocate((float) $item->landed_line_total, $weights);

            $position = 0;
            foreach ($quantities as $variantId => $rowQty) {
                $variant = $siblings->firstWhere('id', $variantId);

                $rows[] = [
                    'variant' => $variant,
                    'label' => $this->label($variant),
                    'stock' => $stocks[$variantId] ?? 0.0,
                    'qty' => $rowQty,
                    'line_total' => $lineTotals[$position],
                    'tax_amount' => $taxes[$position],
                    'landed_line_total' => $landed[$position],
                ];

                $position++;
            }
        }

        return [
            'item' => $item,
            'bare' => $bare,
            'product' => $bare->product?->name ?? $bare->sku,
            'qty' => $qty,
            'line_total' => round((float) $item->line_total, 2),
            'stock_total' => round(array_sum($stocks), 3),
            'exact' => $exact,
            'note' => $note,
            'rows' => $rows,
        ];
    }

    /**
     * توزيعٌ من الرصيد الحالي للمقاسات.
     *
     * @param  Collection<int, ProductVariant>  $siblings
     * @param  array<int, float>  $stocks
     * @return array{0: array<int, float>, 1: bool, 2: string|null}
     */
    private function fromStock(Collection $siblings, array $stocks, float $qty): array
    {
        $total = round(array_sum($stocks), 3);

        if ($total <= 0) {
            return [[], false, 'مقاسات هذا الصنف كلّها بلا رصيد — لا يُستدلّ منها على توزيع. حدّده يدويًّا.'];
        }

        // المطابق تمامًا: الرصيد هو التوزيع نفسه، تُؤخذ أرقامه كما هي بلا قسمة.
        if (abs($total - $qty) < 1e-6) {
            $quantities = [];
            foreach ($siblings as $variant) {
                $stock = $stocks[$variant->id] ?? 0.0;
                if ($stock > 0) {
                    $quantities[$variant->id] = round($stock, 3);
                }
            }

            return [$quantities, true, null];
        }

        // مخالف: بِيع أو أُضيف بعد التوزيع. تُوزَّع الكمية بنسبة الأرصدة، ويُنبَّه.
        $weights = [];
        foreach ($siblings as $variant) {
            $stock = $stocks[$variant->id] ?? 0.0;
            if ($stock > 0) {
                $weights[$variant->id] = $stock;
            }
        }

        $shares = $this->allocate($qty, array_values($weights), 3);
        $quantities = array_combine(array_keys($weights), $shares);

        return [
            $quantities,
            false,
            'رصيد المقاسات ('.$this->number($total).') يخالف كمية البند ('.$this->number($qty).') — '
                .'بِيع أو أُضيف بعد التوزيع. الأرقام أدناه نسبيّة: راجعها أو مرّر توزيعًا صريحًا.',
        ];
    }

    /**
     * توزيعٌ صريح من المستخدم — يُقبل فقط إن طابق مجموعُه كمية البند تمامًا.
     *
     * @param  array<int, float>  $override
     * @param  Collection<int, ProductVariant>  $siblings
     * @return array{0: array<int, float>, 1: bool, 2: string|null}
     */
    private function fromOverride(array $override, Collection $siblings, float $qty): array
    {
        $quantities = [];

        foreach ($override as $variantId => $rowQty) {
            $rowQty = round((float) $rowQty, 3);

            if ($rowQty <= 0) {
                continue;
            }

            if (! $siblings->contains('id', (int) $variantId)) {
                return [[], false, 'المتغيّر #'.$variantId.' ليس مقاسًا من مقاسات هذا الصنف.'];
            }

            $quantities[(int) $variantId] = $rowQty;
        }

        if ($quantities === []) {
            return [[], false, 'التوزيع الصريح فارغ.'];
        }

        $sum = round(array_sum($quantities), 3);

        if (abs($sum - $qty) >= 1e-6) {
            return [[], false, 'مجموع التوزيع ('.$this->number($sum).') يخالف كمية البند ('.$this->number($qty).').'];
        }

        return [$quantities, true, null];
    }

    /**
     * @param  Collection<int, ProductVariant>  $siblings
     * @return array<int, float>
     */
    private function stocks(Collection $siblings, ?Warehouse $warehouse): array
    {
        if ($warehouse === null) {
            return [];
        }

        return InventoryStock::query()
            ->whereIn('variant_id', $siblings->pluck('id'))
            ->where('warehouse_id', $warehouse->id)
            ->pluck('on_hand', 'variant_id')
            ->map(fn ($on) => round((float) $on, 3))
            ->all();
    }

    /**
     * توزيع مبلغ على أوزان بحيث **يساوي مجموع النواتج المبلغ تمامًا**.
     *
     * القسمة تُنتج كسورًا تُقرَّب، ومجموع المقرَّب يخالف الأصل بقرشٍ أو قرشين —
     * وقرشٌ واحد يكفي ليخالف مجموعُ البنود إجماليَّ الفاتورة فيسقط القيد. فالباقي
     * يُسنَد لأكبر وزنٍ: أقلّ الأسطر تأثّرًا نسبيًّا بقرش.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private function allocate(float $total, array $weights, int $precision = 2): array
    {
        $sum = array_sum($weights);

        if ($weights === [] || $sum <= 0) {
            return array_fill(0, count($weights), 0.0);
        }

        $shares = [];
        foreach ($weights as $weight) {
            $shares[] = round($total * $weight / $sum, $precision);
        }

        $remainder = round($total - array_sum($shares), $precision);

        if (abs($remainder) >= 10 ** (-$precision) / 2) {
            $largest = array_search(max($weights), $weights, true);
            $shares[$largest] = round($shares[$largest] + $remainder, $precision);
        }

        return $shares;
    }

    private function label(?ProductVariant $variant): string
    {
        if (! $variant) {
            return '—';
        }

        $label = $variant->optionLabel();

        return $label !== '' ? $label : ($variant->sku ?: '#'.$variant->id);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    /**
     * أثرٌ مقروء في سجلّ التدقيق: العملية تُغيّر ما تقوله الفاتورة عن نفسها،
     * فلا تجوز بلا توقيع.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $entry
     */
    private function log(
        PurchaseInvoice $invoice,
        PurchaseInvoiceItem $item,
        array $before,
        array $entry,
        ?User $actor,
    ): void {
        AuditLog::create([
            'user_id' => $actor?->id ?? auth()->id(),
            'action' => 'purchase_invoice.variant_split',
            'auditable_type' => $invoice->getMorphClass(),
            'auditable_id' => $invoice->id,
            'old_values' => ['item_id' => $item->id] + $before,
            'new_values' => [
                'item_id' => $item->id,
                'product' => $entry['product'],
                'rows' => array_map(fn (array $row) => [
                    'variant_id' => $row['variant']->id,
                    'label' => $row['label'],
                    'qty' => $row['qty'],
                    'line_total' => $row['line_total'],
                ], $entry['rows']),
                'stock_touched' => false,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
