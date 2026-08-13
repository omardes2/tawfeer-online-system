<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * محرّك التقارير والتحليلات (Phase 4.7 / ADR-042).
 *
 * **للقراءة فقط** — تجميعات فوق نفس نماذج/جداول الأعمال القائمة (طلبات، عمولات، شحنات،
 * مرتجعات، تسويات، مدفوعات، عملاء...). لا يكرّر أي منطق أعمال ولا يكتب شيئًا؛ يقرأ الدفاتر
 * والجداول التي تكتبها الخدمات. تجميعات SQL للأداء (فلترة سريعة).
 */
class ReportingService
{
    /**
     * صافي المبيعات في SQL: الإجمالي بلا رسوم التوصيل — وهو المبلغ الذي يدخل الدفاتر
     * وأساس احتساب العمولات. رسوم التوصيل تخصّ شركة التوصيل ولا تُعدّ إيرادًا لنا.
     */
    private const NET_SALES = 'orders.total - orders.shipping_total';

    /**
     * جدول الطلبات مستثنيًا المحذوف ناعمًا.
     *
     * الاستعلام الخام يتجاوز `SoftDeletes`، فكان الطلب المحذوف يبقى في كل التقارير
     * رغم عكس قيوده المحاسبية — رقمان متناقضان لنفس الطلب.
     */
    private function ordersTable(): Builder
    {
        return DB::table('orders')->whereNull('orders.deleted_at');
    }

    /** @return array<string, float|int> */
    public function executive(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $orders = $this->ordersTable()->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled');

        return [
            'orders' => (int) (clone $orders)->count(),
            'sales_total' => $this->money((clone $orders)->sum(DB::raw(self::NET_SALES))),
            'delivered' => (int) $this->ordersTable()->whereBetween('delivered_at', [$from, $to])->count(),
            'commissions' => $this->money(DB::table('commission_entries')->whereBetween('created_at', [$from, $to])->where('entry_type', 'accrual')->sum('amount')),
            'returns' => (int) DB::table('return_requests')->whereBetween('created_at', [$from, $to])->count(),
            'settlements_net' => $this->money(DB::table('delivery_settlements')->whereBetween('posted_at', [$from, $to])->where('status', 'posted')->sum('computed_net')),
            'new_customers' => (int) DB::table('customers')->whereBetween('created_at', [$from, $to])->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function sales(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $base = fn () => $this->ordersTable()->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled');

        return [
            'total' => $this->money($base()->sum(DB::raw(self::NET_SALES))),
            'count' => (int) $base()->count(),
            'by_status' => $base()->selectRaw('status, COUNT(*) as c, SUM('.self::NET_SALES.') as t')->groupBy('status')->get(),
            'by_channel' => $base()->selectRaw('channel, COUNT(*) as c, SUM('.self::NET_SALES.') as t')->groupBy('channel')->get(),
            'daily' => $base()->selectRaw('DATE(created_at) as d, SUM('.self::NET_SALES.') as t')->groupBy('d')->orderBy('d')->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function orders(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $base = fn () => $this->ordersTable()->whereBetween('created_at', [$from, $to]);
        $count = (int) $base()->where('status', '!=', 'cancelled')->count();
        $total = $this->money($base()->where('status', '!=', 'cancelled')->sum(DB::raw(self::NET_SALES)));

        return [
            'count' => $count,
            'avg_value' => $count > 0 ? round($total / $count, 2) : 0.0,
            'by_status' => $base()->selectRaw('status, COUNT(*) as c')->groupBy('status')->get(),
            'by_channel' => $base()->selectRaw('channel, COUNT(*) as c')->groupBy('channel')->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function delivery(DateRange $range): array
    {
        [$from, $to] = $range->bounds();

        return [
            'by_status' => DB::table('shipments')->whereBetween('created_at', [$from, $to])
                ->selectRaw('delivery_status, COUNT(*) as c')->groupBy('delivery_status')->get(),
            'closed' => (int) DB::table('shipments')->whereBetween('closed_at', [$from, $to])->count(),
            'hold_reasons' => DB::table('delivery_status_transitions')->whereBetween('created_at', [$from, $to])
                ->where('to_status', 'on_hold')->whereNotNull('reason_code')
                ->selectRaw('reason_code, COUNT(*) as c')->groupBy('reason_code')->orderByDesc('c')->get(),
            'exceptions_open' => (int) DB::table('delivery_exceptions')->whereNotIn('status', ['resolved'])->count(),
            'by_provider' => DB::table('shipments')
                ->leftJoin('delivery_providers', 'shipments.delivery_provider_id', '=', 'delivery_providers.id')
                ->whereBetween('shipments.created_at', [$from, $to])
                ->selectRaw('COALESCE(delivery_providers.name, ?) as name, COUNT(*) as c', [__('reports.no_provider')])
                ->groupBy('name')->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function finance(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $settled = DB::table('delivery_settlements')->whereBetween('posted_at', [$from, $to])->where('status', 'posted');

        return [
            'cod_collected' => $this->money((clone $settled)->sum('computed_cod_total')),
            'fees' => $this->money((clone $settled)->sum('computed_fees_total')),
            'net' => $this->money((clone $settled)->sum('computed_net')),
            'settlements' => (int) (clone $settled)->count(),
            'refunds' => $this->money(DB::table('payments')->whereBetween('refunded_at', [$from, $to])->sum('refunded_amount')),
            'commission_eligible' => $this->money(DB::table('commission_entries')->where('state', 'eligible')->sum('amount')),
        ];
    }

    /** أداء موظفي المبيعات. @return Collection<int, object> */
    public function salesEmployees(DateRange $range): Collection
    {
        [$from, $to] = $range->bounds();

        return $this->ordersTable()
            ->join('users', 'orders.assigned_to', '=', 'users.id')
            ->whereBetween('orders.created_at', [$from, $to])->where('orders.status', '!=', 'cancelled')
            ->whereNotNull('orders.assigned_to')
            ->selectRaw('users.id, users.name, COUNT(*) as orders_count, SUM(orders.total - orders.shipping_total) as sales_total')
            ->groupBy('users.id', 'users.name')->orderByDesc('sales_total')
            ->get()
            ->map(function ($row) use ($from, $to) {
                $row->commissions = $this->money(DB::table('commission_entries')
                    ->where('earner_type', 'sales')->where('earner_id', $row->id)->where('entry_type', 'accrual')
                    ->whereBetween('created_at', [$from, $to])->sum('amount'));
                $row->sales_total = $this->money($row->sales_total);

                return $row;
            });
    }

    /** أداء المسوّقين. @return Collection<int, object> */
    public function marketers(DateRange $range): Collection
    {
        [$from, $to] = $range->bounds();

        return $this->ordersTable()
            ->join('users', 'orders.affiliate_id', '=', 'users.id')
            ->whereBetween('orders.created_at', [$from, $to])->where('orders.status', '!=', 'cancelled')
            ->whereNotNull('orders.affiliate_id')
            ->selectRaw('users.id, users.name, COUNT(*) as orders_count, SUM(orders.total - orders.shipping_total) as sales_total')
            ->groupBy('users.id', 'users.name')->orderByDesc('sales_total')
            ->get()
            ->map(function ($row) use ($from, $to) {
                $row->earnings = $this->money(DB::table('commission_entries')
                    ->where('earner_type', 'affiliate')->where('earner_id', $row->id)->where('entry_type', 'accrual')
                    ->whereBetween('created_at', [$from, $to])->sum('amount'));
                $row->sales_total = $this->money($row->sales_total);

                return $row;
            });
    }

    /**
     * لوحة أداء المستفيدين لليوم/أمس/الشهر — جدول لموظفي المبيعات وآخر للمسوّقين.
     *
     * المبالغ صافية من رسوم التوصيل، أي نفس المبلغ الذي يدخل الدفاتر وتُحتسب عليه
     * العمولة، فلا يختلف رقم اللوحة عن رقم التقرير أو كشف العمولة.
     *
     * أصحاب الدور يظهرون ولو بلا طلبات (أصفار) — غياب الاسم يُقرأ خطأً على أنه
     * غياب الموظف لا غياب مبيعاته.
     *
     * @param  'assigned_to'|'affiliate_id'  $column  عمود ربط الطلب بصاحبه
     * @param  array<int, string>  $roles  أدوار من يظهر ولو بلا مبيعات
     * @return Collection<int, array{name: string, orders_today: int, sales_today: float, sales_yesterday: float, sales_month: float}>
     */
    public function earnerBoard(string $column, array $roles): Collection
    {
        $today = today();
        $spans = [
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'yesterday' => [$today->copy()->subDay()->startOfDay(), $today->copy()->subDay()->endOfDay()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        ];

        $sums = [];
        $bindings = [];
        foreach ($spans as $key => [$from, $to]) {
            $sums[] = 'SUM(CASE WHEN orders.created_at BETWEEN ? AND ? THEN '.self::NET_SALES." ELSE 0 END) as sales_{$key}";
            $bindings[] = $from;
            $bindings[] = $to;
        }
        // عدد الطلبيات لليوم — بمحاذاة عمود مبيعات اليوم.
        $sums[] = 'SUM(CASE WHEN orders.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as orders_today';
        $bindings[] = $spans['today'][0];
        $bindings[] = $spans['today'][1];

        $aggregates = $this->ordersTable()
            ->join('users', 'users.id', '=', 'orders.'.$column)
            ->whereNotNull('orders.'.$column)
            ->whereNotIn('orders.status', ['draft', 'new', 'cancelled'])
            ->selectRaw('users.id, users.name, '.implode(', ', $sums), $bindings)
            ->groupBy('users.id', 'users.name')
            ->get()
            ->keyBy('id');

        // الاتحاد: أصحاب الدور ∪ من له طلبات فعلًا (فلا يسقط من غُيِّر دوره لاحقًا).
        $people = DB::table('users')
            ->whereNull('users.deleted_at')
            ->where(fn ($q) => $q
                ->whereIn('users.id', DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereIn('roles.name', $roles)->select('model_has_roles.model_id'))
                ->orWhereIn('users.id', $aggregates->keys()->all()))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        return $people
            ->map(function ($person) use ($aggregates) {
                $row = $aggregates->get($person->id);

                return [
                    'name' => $person->name,
                    'orders_today' => (int) ($row->orders_today ?? 0),
                    'sales_today' => $this->money($row->sales_today ?? 0),
                    'sales_yesterday' => $this->money($row->sales_yesterday ?? 0),
                    'sales_month' => $this->money($row->sales_month ?? 0),
                ];
            })
            ->sortByDesc('sales_month')
            ->values();
    }

    /** أداء المنتجات (الأعلى مبيعًا). @return Collection<int, object> */
    public function products(DateRange $range, int $limit = 20): Collection
    {
        [$from, $to] = $range->bounds();

        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$from, $to])->where('orders.status', '!=', 'cancelled')
            ->selectRaw('product_variants.id, COALESCE(products.name, product_variants.sku) as name, SUM(order_items.qty) as qty, SUM(order_items.line_total) as revenue, SUM(order_items.returned_qty) as returned')
            ->groupBy('product_variants.id', 'products.name', 'product_variants.sku')->orderByDesc('revenue')->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->revenue = $this->money($row->revenue);

                return $row;
            });
    }

    /** إحصاءات العملاء. @return array<string, mixed> */
    public function customers(DateRange $range, int $limit = 20): array
    {
        [$from, $to] = $range->bounds();

        $top = $this->ordersTable()->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, MAX(customer_name) as name, COUNT(*) as orders_count, SUM(total) as total')
            ->groupBy('customer_id')->orderByDesc('total')->limit($limit)->get()
            ->map(function ($row) {
                $row->total = $this->money($row->total);

                return $row;
            });

        $repeat = $this->ordersTable()->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')
            ->whereNotNull('customer_id')
            ->select('customer_id')->groupBy('customer_id')->havingRaw('COUNT(*) > 1')->get()->count();

        return [
            'new_customers' => (int) DB::table('customers')->whereBetween('created_at', [$from, $to])->count(),
            'repeat_customers' => $repeat,
            'top' => $top,
        ];
    }

    /** إحصاءات شركات التوصيل. @return Collection<int, object> */
    public function deliveryCompanies(DateRange $range): Collection
    {
        [$from, $to] = $range->bounds();

        return DB::table('shipments')
            ->leftJoin('delivery_providers', 'shipments.delivery_provider_id', '=', 'delivery_providers.id')
            ->whereBetween('shipments.created_at', [$from, $to])
            ->selectRaw('COALESCE(delivery_providers.name, ?) as name, COUNT(*) as shipments, '.
                "SUM(CASE WHEN shipments.delivery_status = 'closed' THEN 1 ELSE 0 END) as closed, ".
                "SUM(CASE WHEN shipments.kind = 'return_pickup' THEN 1 ELSE 0 END) as returns", [__('reports.no_provider')])
            ->groupBy('name')->orderByDesc('shipments')->get();
    }

    /** إحصاءات المرتجعات والاستبدال. @return array<string, mixed> */
    public function returns(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $base = fn () => DB::table('return_requests')->whereBetween('created_at', [$from, $to]);

        return [
            'count' => (int) $base()->count(),
            'refund_total' => $this->money($base()->where('resolution', 'refund')->sum('refund_amount')),
            'by_reason' => $base()->selectRaw('reason_code, COUNT(*) as c')->groupBy('reason_code')->orderByDesc('c')->get(),
            'by_type' => $base()->selectRaw('type, COUNT(*) as c')->groupBy('type')->get(),
            'by_resolution' => $base()->selectRaw('resolution, COUNT(*) as c')->groupBy('resolution')->get(),
            'by_route' => DB::table('return_request_items')
                ->join('return_requests', 'return_request_items.return_request_id', '=', 'return_requests.id')
                ->whereBetween('return_requests.created_at', [$from, $to])->whereNotNull('return_request_items.inventory_route')
                ->selectRaw('return_request_items.inventory_route, COUNT(*) as c')->groupBy('return_request_items.inventory_route')->get(),
        ];
    }

    /**
     * لوحة مؤشّرات الأداء الموسّعة (Phase 6 / ADR-047) — للقراءة فقط.
     *
     * تجميعة واحدة تجمع مؤشّرات المبيعات والتحصيل والربح والتوصيل والمرتجعات والتسويق
     * والتوصيات والذكاء الاصطناعي فوق الجداول القائمة. لا تكرار منطق أعمال ولا كتابة.
     *
     * @return array<string, mixed>
     */
    public function kpis(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $orders = fn () => $this->ordersTable()->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled');

        $salesTotal = $this->money($orders()->sum(DB::raw(self::NET_SALES)));
        $ordersCount = (int) $orders()->count();

        // تحصيل وتسوية.
        $collected = $this->money(DB::table('delivery_settlements')->whereBetween('posted_at', [$from, $to])->where('status', 'posted')->sum('computed_cod_total'));
        $deliveredTotal = $this->money($this->ordersTable()->whereBetween('delivered_at', [$from, $to])->sum(DB::raw(self::NET_SALES)));
        $unsettled = $this->money(max(0, $deliveredTotal - $collected));

        // ربح إجمالي: إجمالي السطور − (الكمية × تكلفة وقت البيع). تُعتمد لقطة السطر
        // (نفس أساس AnalyticsService وقيد التكلفة)، مع الرجوع لمتوسّط التكلفة عند غيابها.
        $grossProfit = $this->money(DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$from, $to])->where('orders.status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(order_items.line_total - (order_items.qty * COALESCE(order_items.wholesale_cost_snapshot, product_variants.average_cost, 0))), 0) as gp')
            ->value('gp'));

        // توصيل.
        $shipmentsBase = fn () => DB::table('shipments')
            ->join('orders', 'shipments.order_id', '=', 'orders.id')->whereNull('orders.deleted_at')
            ->whereBetween('shipments.created_at', [$from, $to]);
        $shipments = (int) $shipmentsBase()->count();
        $closed = (int) $shipmentsBase()->where('shipments.delivery_status', 'closed')->count();
        $exceptions = (int) DB::table('delivery_exceptions')->whereBetween('created_at', [$from, $to])->count();

        // مرتجعات.
        $returnsCount = (int) DB::table('return_requests')->whereBetween('created_at', [$from, $to])->count();

        // سلال مهجورة.
        $abandonedCarts = (int) DB::table('carts')->whereBetween('updated_at', [$from, $to])->where('status', 'abandoned')->count();

        // أداء الحملات التسويقية.
        $campaignAgg = DB::table('campaign_messages')->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as total, '.
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")->first();

        // أداء التوصيات.
        $recoAgg = DB::table('recommendation_events')->whereBetween('created_at', [$from, $to])
            ->selectRaw("SUM(CASE WHEN event = 'impression' THEN 1 ELSE 0 END) as impressions, ".
                "SUM(CASE WHEN event = 'click' THEN 1 ELSE 0 END) as clicks, ".
                "SUM(CASE WHEN event = 'conversion' THEN 1 ELSE 0 END) as conversions")->first();

        // استخدام الذكاء الاصطناعي وتكلفته (رموز).
        $aiAgg = DB::table('ai_generation_logs')->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as tokens')->first();

        return [
            'sales' => [
                'total' => $salesTotal,
                'orders' => $ordersCount,
                'avg_order_value' => $ordersCount > 0 ? round($salesTotal / $ordersCount, 2) : 0.0,
                'collected' => $collected,
                'unsettled' => $unsettled,
                'gross_profit' => $grossProfit,
                'by_channel' => $orders()->selectRaw('channel, COUNT(*) as c, SUM(total) as t')->groupBy('channel')->get(),
            ],
            'employees' => $this->salesEmployees($range),
            'marketers' => $this->marketers($range),
            'commissions_eligible' => $this->money(DB::table('commission_entries')->where('state', 'eligible')->sum('amount')),
            'delivery' => [
                'shipments' => $shipments,
                'closed' => $closed,
                'success_rate' => $shipments > 0 ? round($closed / $shipments * 100, 1) : 0.0,
                'exceptions' => $exceptions,
                'exception_rate' => $shipments > 0 ? round($exceptions / $shipments * 100, 1) : 0.0,
            ],
            'returns' => [
                'count' => $returnsCount,
                'rate' => $ordersCount > 0 ? round($returnsCount / $ordersCount * 100, 1) : 0.0,
            ],
            'abandoned_carts' => $abandonedCarts,
            'top_products' => $this->products($range, 10),
            'low_stock' => $this->lowStock(),
            'campaigns' => [
                'total' => (int) ($campaignAgg->total ?? 0),
                'sent' => (int) ($campaignAgg->sent ?? 0),
                'failed' => (int) ($campaignAgg->failed ?? 0),
            ],
            'recommendations' => [
                'impressions' => (int) ($recoAgg->impressions ?? 0),
                'clicks' => (int) ($recoAgg->clicks ?? 0),
                'conversions' => (int) ($recoAgg->conversions ?? 0),
                'ctr' => ($recoAgg->impressions ?? 0) > 0 ? round(($recoAgg->clicks ?? 0) / $recoAgg->impressions * 100, 1) : 0.0,
            ],
            'ai' => [
                'requests' => (int) ($aiAgg->requests ?? 0),
                'tokens' => (int) ($aiAgg->tokens ?? 0),
            ],
        ];
    }

    /**
     * أصناف تحت حدّ إعادة الطلب — بنفس سلسلة `WarehouseService` (سطر المخزون ←
     * المتغيّر ← المنتج ← الافتراضي)، فلا يختلف رقم اللوحة عن صفحة تنبيهات النقص.
     */
    private function lowStock(int $limit = 10): Collection
    {
        $level = WarehouseService::reorderLevelExpression();

        return DB::table('inventory_stocks')
            ->join('product_variants', 'inventory_stocks.variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->whereRaw('inventory_stocks.on_hand <= '.$level)
            ->selectRaw('COALESCE(products.name, product_variants.sku) as name, inventory_stocks.on_hand, '.$level.' as reorder_level')
            ->orderBy('inventory_stocks.on_hand')->limit($limit)->get();
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
