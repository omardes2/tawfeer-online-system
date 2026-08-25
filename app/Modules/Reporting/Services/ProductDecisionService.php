<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * لوحة قرار الصنف: ماذا يربح فعلًا، وهل يكفي مخزونه حتى تصل الشحنة القادمة.
 *
 * سؤالان يقرّران تجارة الاستيراد، وكان النظام يعجز عن جوابهما:
 *
 * 1. **ماذا يربح هذا الصنف بعد كل ما يُنفَق عليه؟** تقرير «المبيعات حسب المنتج»
 *    يتوقّف عند الربح **قبل الإعلان**، والصرف الإعلاني مُدخَل عندنا **لكل صنف**
 *    على حدة. فقد يتصدّر صنفٌ المبيعات وهو خاسرٌ فعليًّا: إعلانُه ومرتجعاتُه
 *    تأكل ربحه، ولا شيء في النظام يُظهر ذلك.
 *
 * 2. **متى ينفد؟** «تنبيهات النقص» تقول «نفد» بعد فوات الأوان. والبضاعة تأتي
 *    بالكونتينر في شهور لا أيام، فنفادُ صنفٍ رابح لا يكلّف أسبوعًا بل موسمًا.
 *    فالمطلوب «يكفي 18 يومًا» قبل النفاد لا بعده.
 *
 * كل الأرقام مشتقّة من البيانات الحيّة — لا جدول ملخّصات يُصان ولا يُصدَّق.
 */
class ProductDecisionService
{
    /** حالات لا مبيعَ فيها — نفس أساس تقارير المبيعات كي لا تفترق الأرقام. */
    private const EXCLUDED_STATUSES = ['draft', 'new', 'cancelled'];

    /**
     * حصّة البند بعد المرتجع الجزئي.
     *
     * الضرب في `1.0` ليس زينة: SQLite يقسم الأعداد الصحيحة قسمةً صحيحة، فتعطي
     * `3 / 4` صفرًا وتمحو البند كلَّه بدل أن تخصم ربعه.
     */
    private const NET_RATIO = '(CASE WHEN order_items.qty > 0 '
        .'THEN ((order_items.qty - COALESCE(order_items.returned_qty, 0)) * 1.0) / order_items.qty ELSE 0 END)';

    /** قيمة البند كما بيعت (قبل خصم المرتجع) — أساس توزيع تكلفة التوصيل. */
    private const GROSS_SQL = '(order_items.qty * order_items.unit_price - order_items.discount)';

    private const NET_SALE_SQL = '('.self::GROSS_SQL.' * '.self::NET_RATIO.')';

    private const NET_COST_SQL = '((order_items.qty * COALESCE(order_items.wholesale_cost_snapshot, product_variants.average_cost, 0)) * '.self::NET_RATIO.')';

    /**
     * صفّ لكل صنف بِيع في الفترة أو صُرف عليه إعلانيًّا.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function board(DateRange $range): Collection
    {
        $plan = $this->planningSettings();
        // أيامٌ تقويميّة شاملةٌ للطرفين. `diffInDays` في Carbon 3 كسريّ، ونهاية
        // النطاق 23:59:59، فلولا `startOfDay` لعُدَّ اليومُ الواحد يومين تقريبًا
        // فهبط متوسط البيع اليومي إلى النصف ومعه كلُّ تنبيهات النفاد.
        $days = max(1, (int) $range->from->copy()->startOfDay()
            ->diffInDays($range->to->copy()->startOfDay(), absolute: true) + 1);

        $sales = $this->salesByProduct($range);
        $adSpend = $this->adSpendByProduct($range);
        $delivery = $this->deliveryByProduct($range);

        $ids = $sales->keys()->merge($adSpend->keys())->unique()->values();
        $names = Product::whereIn('id', $ids)->pluck('name', 'id');
        $skus = Product::whereIn('id', $ids)->pluck('sku', 'id');
        $available = $this->availableByProduct($ids);
        $incoming = $this->incomingByProduct($ids);

        return $ids
            ->map(function (int $id) use ($sales, $adSpend, $delivery, $names, $skus, $available, $incoming, $days, $plan) {
                $s = $sales->get($id, ['qty' => 0.0, 'returned' => 0.0, 'sale' => 0.0, 'cost' => 0.0, 'orders' => 0]);
                $ads = round((float) $adSpend->get($id, 0.0), 2);

                // التوصيل **نشاطٌ له طرفان**: ما تدفعه للشركة وما تقبضه من
                // الزبون. وخصمُ المدفوع وحده كان يُسقط الإيراد المقابل فيُظهر
                // نصف الربح — والحكم على الصنف مبنيٌّ على هذا الرقم.
                $ship = round((float) $delivery['cost']->get($id, 0.0), 2);
                $shipRevenue = round((float) $delivery['revenue']->get($id, 0.0), 2);
                $shipNet = round($ship - $shipRevenue, 2);

                $net = round($s['sale'] - $s['cost'] - $ads - $shipNet, 2);

                $onHand = round((float) $available->get($id, 0.0), 3);
                $velocity = round($s['qty'] / $days, 3);
                $cover = $velocity > 0 ? (int) floor($onHand / $velocity) : null;

                return [
                    'product_id' => $id,
                    'product' => $names[$id] ?? __('صنف محذوف'),
                    'sku' => $skus[$id] ?? null,

                    // ── الربح الحقيقي ──
                    'orders_count' => $s['orders'],
                    'qty_sold' => round($s['qty'], 3),
                    'returned_qty' => round($s['returned'], 3),
                    'return_rate' => $s['qty'] + $s['returned'] > 0
                        ? round($s['returned'] / ($s['qty'] + $s['returned']) * 100, 1) : 0.0,
                    'sales' => round($s['sale'], 2),
                    'cogs' => round($s['cost'], 2),
                    'ad_spend' => $ads,
                    'delivery_cost' => $ship,
                    'delivery_revenue' => $shipRevenue,
                    // موجبٌ = التوصيل يكلّفك · سالبٌ = تربح منه.
                    'delivery_net' => $shipNet,
                    'net_profit' => $net,
                    'margin_pct' => $s['sale'] > 0 ? round($net / $s['sale'] * 100, 1) : null,

                    // ── التغطية والشراء ──
                    'available' => $onHand,
                    'velocity' => $velocity,
                    'days_of_cover' => $cover,
                    'incoming' => round((float) $incoming->get($id, 0.0), 3),
                    'suggested_qty' => $this->suggestedQty($velocity, $onHand, (float) $incoming->get($id, 0.0), $plan),

                    'verdict' => $this->verdict($net, $velocity, $cover, $plan),
                ];
            })
            ->sortByDesc('net_profit')
            ->values();
    }

    /** مهلة التوريد ومخزون الأمان — أرقام عملٍ من الإعدادات لا ثوابت كود. */
    public function planningSettings(): array
    {
        return [
            // مهلة الاستيراد بالكونتينر — من الطلب إلى الرفّ.
            'lead_time_days' => max(1, (int) Settings::get('inventory.lead_time_days', 90)),
            // هامشٌ فوق المهلة: التأخير قاعدةٌ لا استثناء في الشحن البحري.
            'safety_days' => max(0, (int) Settings::get('inventory.safety_days', 14)),
        ];
    }

    /**
     * الكمية المقترح طلبها: ما يغطّي المهلة ومخزون الأمان، ناقصًا ما بين يديك
     * وما هو في الطريق. صفرٌ إن كان المتوفّر كافيًا — لا نقترح شراءً بلا حاجة.
     */
    private function suggestedQty(float $velocity, float $available, float $incoming, array $plan): float
    {
        if ($velocity <= 0) {
            return 0.0; // لا يُباع: الشراء له تجميدُ نقدٍ لا استثمار.
        }

        $needed = $velocity * ($plan['lead_time_days'] + $plan['safety_days']);

        return round(max(0, $needed - $available - $incoming), 0);
    }

    /**
     * حكمٌ واحد يختصر الصفّ.
     *
     * الترتيب مقصود: **الخسارة أولًا** — صنفٌ ينفد وهو خاسر لا يستحقّ تنبيه
     * «اطلب المزيد»، بل قرار إيقاف. ثم النفاد، لأنه الأعجل زمنًا.
     */
    private function verdict(float $netProfit, float $velocity, ?int $cover, array $plan): array
    {
        if ($velocity <= 0) {
            return ['key' => 'idle', 'label' => __('راكد'), 'tone' => 'gray',
                'note' => __('لم يُبَع في الفترة — نقدٌ مجمَّد في الرفّ.')];
        }

        if ($netProfit < 0) {
            return ['key' => 'losing', 'label' => __('خاسر'), 'tone' => 'red',
                'note' => __('بعد الإعلان والتوصيل يخسر :n — راجع سعره أو أوقف إعلانه.', ['n' => number_format(abs($netProfit), 2)])];
        }

        $threshold = $plan['lead_time_days'];

        if ($cover !== null && $cover < $threshold) {
            return ['key' => 'reorder', 'label' => __('اطلب الآن'), 'tone' => 'amber',
                'note' => __('يكفي :c يومًا ومهلة التوريد :l يومًا — لو طلبتَ اليوم لنفد قبل أن يصل.', ['c' => $cover, 'l' => $threshold])];
        }

        return ['key' => 'healthy', 'label' => __('سليم'), 'tone' => 'green',
            'note' => __('رابح، ومخزونه يكفي :c يومًا.', ['c' => $cover ?? '—'])];
    }

    // ————————————————————————————————— المصادر —————————————————————————————————

    /** أساس المبيعات: نفس استعلام تقارير المبيعات كي لا تفترق الأرقام. */
    private function soldGoods(DateRange $range): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$range->from, $range->to]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function salesByProduct(DateRange $range): Collection
    {
        return $this->soldGoods($range)
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->groupBy('products.id')
            ->selectRaw('products.id as pid, '
                .'COUNT(DISTINCT orders.id) as orders_count, '
                .'SUM(order_items.qty - COALESCE(order_items.returned_qty, 0)) as net_qty, '
                .'SUM(COALESCE(order_items.returned_qty, 0)) as returned_qty, '
                .'SUM('.self::NET_SALE_SQL.') as sale_total, '
                .'SUM('.self::NET_COST_SQL.') as cost_total')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->pid => [
                'orders' => (int) $r->orders_count,
                'qty' => (float) $r->net_qty,
                'returned' => (float) $r->returned_qty,
                'sale' => (float) $r->sale_total,
                'cost' => (float) $r->cost_total,
            ]]);
    }

    /** الصرف الإعلاني بالشيكل لكل صنف — بسعر صرف يومه لا بسعر اليوم. */
    private function adSpendByProduct(DateRange $range): Collection
    {
        return AdDailySpend::query()
            ->whereNotNull('product_id')
            ->whereBetween('spend_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(amount_usd * fx_rate) as local')
            ->pluck('local', 'product_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v]);
    }

    /**
     * تكلفة التوصيل الفعلية موزَّعةً على أصناف الطلب بحصّتها من قيمته.
     *
     * التكلفة على الطلب لا على الصنف، والطلب قد يحمل أصنافًا عدّة — فتُوزَّع
     * بالتناسب. والتوزيع **بقيمة البيع قبل خصم المرتجع** عمدًا: الطلب المرتجَع
     * كلّه قيمتُه الصافية صفر، فالقسمة عليها تسقط — وتكلفة توصيله دُفعت فعلًا
     * ويجب أن تظهر. وهذا بالضبط ما يكشف أن المرتجعات تأكل ربح الصنف.
     */
    private function deliveryByProduct(DateRange $range): array
    {
        // حصّة كل صنف من كل طلب (بالقيمة الإجمالية قبل المرتجع).
        $shares = $this->soldGoods($range)
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->groupBy('orders.id', 'products.id')
            ->selectRaw('orders.id as oid, products.id as pid, SUM('.self::GROSS_SQL.') as gross')
            ->get();

        if ($shares->isEmpty()) {
            return ['cost' => collect(), 'revenue' => collect()];
        }

        $orderIds = $shares->pluck('oid')->unique();

        // ما دُفع لشركة التوصيل عن كل طلب.
        $costs = Shipment::query()
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(COALESCE(shipping_cost, 0)) as cost')
            ->pluck('cost', 'order_id');

        // وما قُبض من الزبون مقابله — `shipping_total` على الطلب.
        $revenue = Order::whereIn('id', $orderIds)->pluck('shipping_total', 'id');

        $orderTotals = $shares->groupBy('oid')->map(fn ($rows) => (float) $rows->sum('gross'));

        return [
            'cost' => $this->allocate($shares, $costs, $orderTotals),
            // بنفس نسبة توزيع التكلفة تمامًا — وإلّا اختلف مقاما الطرفين فصار
            // الصافي بلا معنى.
            'revenue' => $this->allocate($shares, $revenue, $orderTotals),
        ];
    }

    /**
     * توزيع مبلغِ طلبٍ على أصنافه بحصّة كلٍّ منها من قيمته.
     *
     * @param  Collection<int, object>  $shares
     * @param  Collection<int, mixed>  $amounts  المبلغ لكل طلب
     * @param  Collection<int, float>  $orderTotals
     * @return Collection<int, float>
     */
    private function allocate(Collection $shares, Collection $amounts, Collection $orderTotals): Collection
    {
        $out = [];

        foreach ($shares as $row) {
            $amount = (float) ($amounts[$row->oid] ?? 0);
            $gross = (float) ($orderTotals[$row->oid] ?? 0);

            if ($amount <= 0 || $gross <= 0) {
                continue;
            }

            $pid = (int) $row->pid;
            $out[$pid] = ($out[$pid] ?? 0) + $amount * ((float) $row->gross / $gross);
        }

        return collect($out);
    }

    /** المتاح الآن (الموجود ناقص المحجوز) لكل صنف عبر المستودعات كلّها. */
    private function availableByProduct(Collection $productIds): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        return InventoryStock::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.variant_id')
            ->whereIn('product_variants.product_id', $productIds)
            ->groupBy('product_variants.product_id')
            ->selectRaw('product_variants.product_id as pid, SUM(inventory_stocks.on_hand - inventory_stocks.reserved) as qty')
            ->pluck('qty', 'pid')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v]);
    }

    /**
     * الكميات في الطريق: أصناف الشحنات المفتوحة بفواتير بضاعة مُرحّلة.
     *
     * تُطرح من الكمية المقترحة، وإلا اقترح النظام شراء ما اشتُري فعلًا وما زال
     * في البحر — وهو أسوأ خطأ في تخطيط الاستيراد.
     */
    private function incomingByProduct(Collection $productIds): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        $openShipmentIds = ImportShipment::open()->pluck('id');

        if ($openShipmentIds->isEmpty()) {
            return collect();
        }

        return PurchaseInvoiceItem::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
            ->join('product_variants', 'product_variants.id', '=', 'purchase_invoice_items.variant_id')
            ->whereIn('purchase_invoices.import_shipment_id', $openShipmentIds)
            ->where('purchase_invoices.status', 'posted')
            ->where('purchase_invoices.kind', 'goods')
            ->whereIn('product_variants.product_id', $productIds)
            ->groupBy('product_variants.product_id')
            ->selectRaw('product_variants.product_id as pid, SUM(purchase_invoice_items.qty) as qty')
            ->pluck('qty', 'pid')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v]);
    }
}
