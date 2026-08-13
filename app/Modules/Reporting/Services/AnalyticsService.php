<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * محرّك «التقارير والتحليلات» الموسّع — للقراءة فقط (ADR-042).
 *
 * تجميعات SQL فوق جداول الأعمال القائمة (طلبات، بنود، مخزون، شحنات، مدفوعات،
 * عمولات، عملاء، تدقيق...). لا يكتب شيئًا ولا يكرّر منطق أعمال. الربح = إيراد البند
 * (line_total) ناقص تكلفته (wholesale_cost_snapshot × الكمية) — من لقطات وقت البيع.
 */
class AnalyticsService
{
    private function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    /** بنود الطلبات (غير الملغاة) ضمن النطاق، مربوطة بالطلب. */
    private function items(string $from, string $to)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', 'cancelled');
    }

    /** الطلبات (غير الملغاة) ضمن النطاق. أعمدة مؤهّلة (orders.*) لتفادي الالتباس عند الـjoin. */
    private function orders(string $from, string $to)
    {
        return DB::table('orders')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', 'cancelled');
    }

    /** إيراد/تكلفة/ربح لمجموعة بنود. */
    private function pnl(string $from, string $to): array
    {
        $row = $this->items($from, $to)
            ->selectRaw('COALESCE(SUM(order_items.line_total),0) as revenue')
            ->selectRaw('COALESCE(SUM(order_items.wholesale_cost_snapshot * order_items.qty),0) as cost')
            ->first();

        $revenue = $this->money($row->revenue ?? 0);
        $cost = $this->money($row->cost ?? 0);
        $profit = $this->money($revenue - $cost);

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
        ];
    }

    private function sumSales(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return $this->money(
            $this->orders($from->toDateTimeString(), $to->toDateTimeString())->sum('total')
        );
    }

    // ==================================================================
    // 1) اللوحة التنفيذية
    // ==================================================================
    public function dashboard(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $now = CarbonImmutable::now();
        $orders = $this->orders($from, $to);
        $count = (int) (clone $orders)->count();
        $pnl = $this->pnl($from, $to);
        $revenue = $this->money($orders->sum('total'));
        $commissions = $this->money(DB::table('commission_entries')
            ->whereBetween('created_at', [$from, $to])->where('entry_type', 'accrual')->sum('amount'));
        $shipping = $this->money($this->orders($from, $to)->sum('shipping_total'));

        return [
            'sales_today' => $this->sumSales($now->startOfDay(), $now->endOfDay()),
            'sales_week' => $this->sumSales($now->subDays(6)->startOfDay(), $now->endOfDay()),
            'sales_month' => $this->sumSales($now->startOfMonth(), $now->endOfMonth()),
            'sales_year' => $this->sumSales($now->startOfYear(), $now->endOfYear()),
            'orders' => $count,
            'aov' => $count > 0 ? $this->money($revenue / $count) : 0.0,
            'gross_revenue' => $pnl['revenue'],
            'gross_profit' => $pnl['profit'],
            'net_profit' => $this->money($pnl['profit'] - $commissions - $shipping),
            'customers' => (int) DB::table('customers')->whereNull('deleted_at')->count(),
            'new_customers' => (int) DB::table('customers')->whereBetween('created_at', [$from, $to])->count(),
            'best_products' => $this->topProducts($from, $to, 'desc', 5),
            'worst_products' => $this->topProducts($from, $to, 'asc', 5),
            'low_stock' => $this->lowStock()->count(),
            'out_of_stock' => $this->outOfStock()->count(),
            'by_status' => $this->ordersByStatus($from, $to),
            'by_city' => $this->salesByCity($from, $to, 6),
            'by_employee' => $this->salesByEmployee($from, $to, 6),
            'by_marketer' => $this->salesByMarketer($from, $to, 6),
            'shipping' => $this->shippingPerformance($from, $to),
            'daily' => $this->dailyTrend($from, $to),
        ];
    }

    /** سلسلة يومية للمبيعات (رسم خطّي). */
    public function dailyTrend(string $from, string $to): Collection
    {
        return $this->orders($from, $to)
            ->selectRaw('DATE(created_at) as d, SUM(total) as t, COUNT(*) as c')
            ->groupBy('d')->orderBy('d')->get();
    }

    public function ordersByStatus(string $from, string $to): Collection
    {
        return DB::table('orders')->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as c, COALESCE(SUM(total),0) as t')
            ->groupBy('status')->get();
    }

    /** أعلى/أدنى المنتجات مبيعًا (بالكمية). */
    public function topProducts(string $from, string $to, string $dir = 'desc', int $limit = 20): Collection
    {
        return $this->items($from, $to)
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->selectRaw('products.id, products.name, products.sku')
            ->selectRaw('SUM(order_items.qty) as qty')
            ->selectRaw('COALESCE(SUM(order_items.line_total),0) as revenue')
            ->selectRaw('COALESCE(SUM(order_items.wholesale_cost_snapshot * order_items.qty),0) as cost')
            ->selectRaw('COALESCE(SUM(order_items.returned_qty),0) as returned')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderBy('qty', $dir)->limit($limit)->get()
            ->map(function ($r) {
                $r->profit = $this->money($r->revenue - $r->cost);
                $r->revenue = $this->money($r->revenue);

                return $r;
            });
    }

    public function salesByCity(string $from, string $to, int $limit = 50): Collection
    {
        return $this->orders($from, $to)
            ->leftJoin('cities', 'cities.id', '=', 'orders.city_id')
            ->selectRaw("COALESCE(cities.name, 'غير محدّد') as name, COUNT(*) as c, COALESCE(SUM(orders.total),0) as t")
            ->groupBy('cities.name')->orderBy('t', 'desc')->limit($limit)->get()
            ->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t)));
    }

    public function salesByEmployee(string $from, string $to, int $limit = 50): Collection
    {
        return $this->orders($from, $to)->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'orders.assigned_to')
            ->selectRaw('users.name, COUNT(*) as c, COALESCE(SUM(orders.total),0) as t')
            ->groupBy('users.id', 'users.name')->orderBy('t', 'desc')->limit($limit)->get()
            ->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t)));
    }

    public function salesByMarketer(string $from, string $to, int $limit = 50): Collection
    {
        return $this->orders($from, $to)->whereNotNull('affiliate_id')
            ->join('users', 'users.id', '=', 'orders.affiliate_id')
            ->selectRaw('users.name, COUNT(*) as c, COALESCE(SUM(orders.total),0) as t')
            ->groupBy('users.id', 'users.name')->orderBy('t', 'desc')->limit($limit)->get()
            ->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t)));
    }

    public function shippingPerformance(string $from, string $to): array
    {
        $base = fn () => DB::table('shipments')->whereBetween('created_at', [$from, $to]);
        $delivered = (int) (clone $base())->where('status', 'delivered')->count();
        $returned = (int) (clone $base())->whereIn('status', ['returned', 'return_in_transit'])->count();
        $pending = (int) (clone $base())->whereNotIn('status', ['delivered', 'returned', 'cancelled'])->count();
        $total = (int) $base()->count();

        // متوسّط زمن التسليم (أيام) للشحنات المسلَّمة.
        $avgDays = DB::table('shipments')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('delivered_at')->whereNotNull('dispatched_at')
            ->get(['dispatched_at', 'delivered_at'])
            ->map(fn ($s) => CarbonImmutable::parse($s->dispatched_at)->diffInHours(CarbonImmutable::parse($s->delivered_at)) / 24)
            ->avg();

        return [
            'total' => $total,
            'delivered' => $delivered,
            'returned' => $returned,
            'pending' => $pending,
            'success_rate' => $total > 0 ? round($delivered / $total * 100, 1) : 0.0,
            'avg_days' => $avgDays ? round((float) $avgDays, 1) : 0.0,
            'cost' => $this->money($base()->sum('shipping_cost')),
        ];
    }

    // ==================================================================
    // 2) المبيعات — تفصيلات متعدّدة الأبعاد
    // ==================================================================
    public function sales(DateRange $range): array
    {
        [$from, $to] = $range->bounds();

        return [
            'summary' => array_merge($this->pnl($from, $to), [
                'orders' => (int) $this->orders($from, $to)->count(),
                'total' => $this->money($this->orders($from, $to)->sum('total')),
            ]),
            'daily' => $this->dailyTrend($from, $to),
            'monthly' => $this->orders($from, $to)
                ->selectRaw('SUBSTR(created_at,1,7) as m, COALESCE(SUM(total),0) as t, COUNT(*) as c')
                ->groupBy('m')->orderBy('m')->get()->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t))),
            'by_status' => $this->ordersByStatus($from, $to),
            'by_channel' => $this->orders($from, $to)
                ->selectRaw('channel, COUNT(*) as c, COALESCE(SUM(total),0) as t')
                ->groupBy('channel')->get()->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t))),
            'by_payment' => $this->orders($from, $to)
                ->selectRaw("COALESCE(payment_status,'unpaid') as payment_status, COUNT(*) as c, COALESCE(SUM(total),0) as t")
                ->groupBy('payment_status')->get()->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t))),
            'by_city' => $this->salesByCity($from, $to, 15),
            'by_category' => $this->salesByDimension($from, $to, 'categories', 'products.category_id'),
            'by_brand' => $this->salesByDimension($from, $to, 'brands', 'products.brand_id'),
            'by_product' => $this->topProducts($from, $to, 'desc', 15),
            'by_employee' => $this->salesByEmployee($from, $to, 15),
            'by_marketer' => $this->salesByMarketer($from, $to, 15),
        ];
    }

    /** مبيعات حسب بُعد منتج (فئة/علامة) عبر البنود. */
    private function salesByDimension(string $from, string $to, string $table, string $fk): Collection
    {
        return $this->items($from, $to)
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin($table, "$table.id", '=', $fk)
            ->selectRaw("COALESCE($table.name, 'غير مصنّف') as name")
            ->selectRaw('SUM(order_items.qty) as qty')
            ->selectRaw('COALESCE(SUM(order_items.line_total),0) as revenue')
            ->selectRaw('COALESCE(SUM(order_items.wholesale_cost_snapshot * order_items.qty),0) as cost')
            ->groupBy("$table.name")->orderBy('revenue', 'desc')->limit(30)->get()
            ->map(function ($r) {
                $r->profit = $this->money($r->revenue - $r->cost);
                $r->revenue = $this->money($r->revenue);

                return $r;
            });
    }

    // ==================================================================
    // 3) العملاء
    // ==================================================================
    public function customers(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $now = CarbonImmutable::now();

        $top = DB::table('orders')->whereNotNull('customer_id')->where('status', '!=', 'cancelled')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->selectRaw('customers.id, customers.name, COUNT(*) as orders_count, COALESCE(SUM(orders.total),0) as total')
            ->groupBy('customers.id', 'customers.name')->orderBy('total', 'desc')->limit(20)->get()
            ->map(fn ($r) => tap($r, fn ($x) => $x->total = $this->money($x->total)));

        return [
            'total' => (int) DB::table('customers')->whereNull('deleted_at')->count(),
            'new' => (int) DB::table('customers')->whereBetween('created_at', [$from, $to])->count(),
            'top' => $top,
            'by_city' => DB::table('orders')->whereNotNull('customer_id')
                ->leftJoin('cities', 'cities.id', '=', 'orders.city_id')
                ->selectRaw("COALESCE(cities.name,'غير محدّد') as name, COUNT(DISTINCT orders.customer_id) as c")
                ->groupBy('cities.name')->orderBy('c', 'desc')->limit(15)->get(),
            'with_returns' => DB::table('customers')->where('returns_count', '>', 0)
                ->orderBy('returns_count', 'desc')->limit(20)->get(['id', 'name', 'returns_count', 'cancelled_orders_count']),
            'inactive' => DB::table('customers')->whereNull('deleted_at')
                ->whereNotExists(function ($q) use ($now) {
                    $q->select(DB::raw(1))->from('orders')
                        ->whereColumn('orders.customer_id', 'customers.id')
                        ->where('orders.created_at', '>=', $now->subDays(90)->toDateTimeString());
                })->orderBy('created_at')->limit(20)->get(['id', 'name', 'primary_phone', 'created_at']),
            'birthdays' => DB::table('customers')->whereNotNull('birth_date')
                ->whereRaw('SUBSTR(birth_date,6,5) = ?', [$now->format('m-d')])
                ->limit(20)->get(['id', 'name', 'birth_date', 'primary_phone']),
        ];
    }

    // ==================================================================
    // 4) المنتجات
    // ==================================================================
    public function products(DateRange $range): array
    {
        [$from, $to] = $range->bounds();

        return [
            'best' => $this->topProducts($from, $to, 'desc', 20),
            'worst' => $this->topProducts($from, $to, 'asc', 20),
            'unsold' => DB::table('products')->whereNull('deleted_at')->where('is_active', true)
                ->whereNotExists(function ($q) use ($from, $to) {
                    $q->select(DB::raw(1))->from('order_items')
                        ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
                        ->join('orders', 'orders.id', '=', 'order_items.order_id')
                        ->whereColumn('product_variants.product_id', 'products.id')
                        ->whereBetween('orders.created_at', [$from, $to]);
                })->limit(30)->get(['id', 'name', 'sku', 'retail_price']),
            'low_stock' => $this->lowStock()->take(30)->values(),
            'out_of_stock' => $this->outOfStock()->take(30)->values(),
            'profitability' => $this->topProducts($from, $to, 'desc', 20)
                ->sortByDesc('profit')->values(),
        ];
    }

    // ==================================================================
    // 5) المخزون
    // ==================================================================
    public function inventory(DateRange $range): array
    {
        [$from, $to] = $range->bounds();

        $valueRow = DB::table('inventory_stocks')
            ->selectRaw('COALESCE(SUM(on_hand),0) as units')
            ->selectRaw('COALESCE(SUM(on_hand * average_cost),0) as value')
            ->first();

        $movements = DB::table('inventory_movements')->whereBetween('created_at', [$from, $to]);

        return [
            'total_units' => (float) ($valueRow->units ?? 0),
            'total_value' => $this->money($valueRow->value ?? 0),
            'skus' => (int) DB::table('inventory_stocks')->where('on_hand', '>', 0)->count(),
            'low_stock' => $this->lowStock()->take(50)->values(),
            'out_of_stock' => $this->outOfStock()->take(50)->values(),
            'dead_stock' => DB::table('inventory_stocks')
                ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.variant_id')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('inventory_stocks.on_hand', '>', 0)
                ->where(function ($q) use ($from) {
                    $q->whereNull('inventory_stocks.last_movement_at')
                        ->orWhere('inventory_stocks.last_movement_at', '<', CarbonImmutable::parse($from)->subDays(90)->toDateTimeString());
                })
                ->selectRaw('products.name, product_variants.sku, inventory_stocks.on_hand, inventory_stocks.average_cost, inventory_stocks.last_movement_at')
                ->orderBy('inventory_stocks.on_hand', 'desc')->limit(50)->get(),
            'by_type' => (clone $movements)->selectRaw('type, COUNT(*) as c, COALESCE(SUM(ABS(qty)),0) as qty')
                ->groupBy('type')->get(),
            'incoming' => (float) (clone $movements)->where('qty', '>', 0)->sum('qty'),
            'outgoing' => (float) abs((clone $movements)->where('qty', '<', 0)->sum('qty')),
            'recent' => DB::table('inventory_movements')->whereBetween('inventory_movements.created_at', [$from, $to])
                ->join('product_variants', 'product_variants.id', '=', 'inventory_movements.variant_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
                ->leftJoin('warehouses', 'warehouses.id', '=', 'inventory_movements.warehouse_id')
                ->selectRaw('inventory_movements.created_at, products.name as product, warehouses.name as warehouse, inventory_movements.type, inventory_movements.qty, inventory_movements.reason')
                ->orderByDesc('inventory_movements.id')->limit(40)->get(),
        ];
    }

    public function lowStock(): Collection
    {
        return DB::table('inventory_stocks')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereRaw('inventory_stocks.on_hand <= '.WarehouseService::reorderLevelExpression())
            ->where('inventory_stocks.on_hand', '>', 0)
            ->selectRaw('products.name, product_variants.sku, inventory_stocks.on_hand, '
                .WarehouseService::reorderLevelExpression().' as reorder_level')
            ->orderBy('inventory_stocks.on_hand')->get();
    }

    public function outOfStock(): Collection
    {
        return DB::table('inventory_stocks')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('inventory_stocks.on_hand', '<=', 0)
            ->where('products.track_inventory', true)
            ->selectRaw('products.name, product_variants.sku, inventory_stocks.on_hand, inventory_stocks.reorder_level')
            ->orderBy('products.name')->get();
    }

    // ==================================================================
    // 6) الشحن والتوصيل
    // ==================================================================
    public function shipping(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $base = fn () => DB::table('shipments')->whereBetween('shipments.created_at', [$from, $to]);

        return [
            'performance' => $this->shippingPerformance($from, $to),
            'by_status' => (clone $base())->selectRaw('status, COUNT(*) as c')->groupBy('status')->get(),
            'by_city' => (clone $base())->leftJoin('cities', 'cities.id', '=', 'shipments.city_id')
                ->selectRaw("COALESCE(cities.name,'غير محدّد') as name, COUNT(*) as c, COALESCE(SUM(shipments.shipping_cost),0) as cost")
                ->groupBy('cities.name')->orderBy('c', 'desc')->limit(15)->get()
                ->map(fn ($r) => tap($r, fn ($x) => $x->cost = $this->money($x->cost))),
            'by_provider' => (clone $base())->leftJoin('delivery_providers', 'delivery_providers.id', '=', 'shipments.delivery_provider_id')
                ->selectRaw("COALESCE(delivery_providers.name,'—') as name, COUNT(*) as c")
                ->selectRaw("SUM(CASE WHEN shipments.status = 'delivered' THEN 1 ELSE 0 END) as delivered")
                ->selectRaw("SUM(CASE WHEN shipments.status IN ('returned','return_in_transit') THEN 1 ELSE 0 END) as returned")
                ->groupBy('delivery_providers.name')->orderBy('c', 'desc')->get(),
            'return_reasons' => DB::table('return_requests')->whereBetween('created_at', [$from, $to])
                ->selectRaw("COALESCE(reason_code,'غير محدّد') as reason, COUNT(*) as c")
                ->groupBy('reason_code')->orderBy('c', 'desc')->limit(15)->get(),
            'pending_list' => (clone $base())->whereNotIn('status', ['delivered', 'returned', 'cancelled'])
                ->leftJoin('cities', 'cities.id', '=', 'shipments.city_id')
                ->selectRaw('shipments.number, shipments.status, shipments.recipient_name, cities.name as city, shipments.created_at')
                ->orderByDesc('shipments.id')->limit(40)->get(),
        ];
    }

    // ==================================================================
    // 7) المالية
    // ==================================================================
    public function financial(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $pnl = $this->pnl($from, $to);
        $commissions = $this->money(DB::table('commission_entries')
            ->whereBetween('created_at', [$from, $to])->where('entry_type', 'accrual')->sum('amount'));
        $shipping = $this->money($this->orders($from, $to)->sum('shipping_total'));

        return [
            'revenue' => $pnl['revenue'],
            'cogs' => $pnl['cost'],
            'gross_profit' => $pnl['profit'],
            'commissions' => $commissions,
            'shipping' => $shipping,
            'net_profit' => $this->money($pnl['profit'] - $commissions - $shipping),
            'collected' => $this->money(DB::table('payments')->whereBetween('paid_at', [$from, $to])
                ->where('status', 'paid')->sum('amount')),
            'daily_profit' => $this->items($from, $to)
                ->selectRaw('DATE(orders.created_at) as d')
                ->selectRaw('COALESCE(SUM(order_items.line_total),0) as revenue')
                ->selectRaw('COALESCE(SUM(order_items.wholesale_cost_snapshot * order_items.qty),0) as cost')
                ->groupBy('d')->orderBy('d')->get()
                ->map(function ($r) {
                    $r->profit = $this->money($r->revenue - $r->cost);

                    return $r;
                }),
            'by_payment_method' => DB::table('payments')->whereBetween('payments.created_at', [$from, $to])
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                ->selectRaw("COALESCE(payment_methods.name,'—') as name, COUNT(*) as c, COALESCE(SUM(payments.amount),0) as t")
                ->where('payments.status', 'paid')
                ->groupBy('payment_methods.name')->get()->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t))),
            'treasuries' => DB::table('treasuries')->whereNull('deleted_at')
                ->get(['name', 'type', 'opening_balance', 'currency']),
            'customer_balances' => $this->money(DB::table('orders')->where('status', '!=', 'cancelled')
                ->whereColumn('amount_paid', '<', 'total')
                ->selectRaw('COALESCE(SUM(total - amount_paid),0) as due')->value('due')),
            'supplier_balances' => $this->money(DB::table('suppliers')->whereNull('deleted_at')->sum('opening_balance')),
        ];
    }

    // ==================================================================
    // 8) الموظفون  |  9) المسوّقون
    // ==================================================================
    public function earners(DateRange $range, string $earnerType, string $orderColumn): array
    {
        [$from, $to] = $range->bounds();

        $rows = $this->orders($from, $to)->whereNotNull($orderColumn)
            ->join('users', 'users.id', '=', "orders.$orderColumn")
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw('users.id, users.name')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders_count')
            ->selectRaw('COALESCE(SUM(order_items.line_total),0) as revenue')
            ->selectRaw('COALESCE(SUM(order_items.wholesale_cost_snapshot * order_items.qty),0) as cost')
            ->groupBy('users.id', 'users.name')->orderBy('revenue', 'desc')->get()
            ->map(function ($r) {
                $r->revenue = $this->money($r->revenue);
                $r->profit = $this->money($r->revenue - $r->cost);

                return $r;
            });

        // العمولات المستحقّة لكل جهة (earner) ضمن النطاق.
        $commissions = DB::table('commission_entries')
            ->whereBetween('created_at', [$from, $to])->where('earner_type', $earnerType)
            ->where('entry_type', 'accrual')
            ->selectRaw('earner_id, COALESCE(SUM(amount),0) as commission')
            ->groupBy('earner_id')->pluck('commission', 'earner_id');

        $rows = $rows->map(function ($r) use ($commissions) {
            $r->commission = $this->money($commissions[$r->id] ?? 0);

            return $r;
        });

        return [
            'rows' => $rows,
            'total_revenue' => $this->money($rows->sum('revenue')),
            'total_orders' => (int) $rows->sum('orders_count'),
            'total_commission' => $this->money($rows->sum('commission')),
        ];
    }

    // ==================================================================
    // 10) التسويق
    // ==================================================================
    public function marketing(DateRange $range): array
    {
        [$from, $to] = $range->bounds();

        return [
            'campaigns_total' => (int) DB::table('campaigns')->whereNull('deleted_at')->count(),
            'by_status' => DB::table('campaigns')->whereNull('deleted_at')
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->get(),
            'by_channel' => DB::table('campaigns')->whereNull('deleted_at')
                ->selectRaw('channel, COUNT(*) as c')->groupBy('channel')->get(),
            'campaigns' => DB::table('campaigns')->whereNull('deleted_at')
                ->orderByDesc('id')->limit(30)->get(['name', 'channel', 'status', 'use_case', 'created_at']),
            // مصدر الطلب (channel) كبديل واقعي لـ«المنصّة» حتى ربط الحملات بالطلبات.
            'orders_by_source' => $this->orders($from, $to)
                ->selectRaw('channel, COUNT(*) as c, COALESCE(SUM(total),0) as t')
                ->groupBy('channel')->get()->map(fn ($r) => tap($r, fn ($x) => $x->t = $this->money($x->t))),
        ];
    }

    // ==================================================================
    // 12) التدقيق
    // ==================================================================
    public function audit(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $base = fn () => DB::table('audit_logs')->whereBetween('audit_logs.created_at', [$from, $to]);

        return [
            'total' => (int) $base()->count(),
            'by_action' => (clone $base())->selectRaw('action, COUNT(*) as c')
                ->groupBy('action')->orderBy('c', 'desc')->limit(15)->get(),
            'by_user' => (clone $base())->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->selectRaw("COALESCE(users.name,'النظام') as name, COUNT(*) as c")
                ->groupBy('users.name')->orderBy('c', 'desc')->limit(15)->get(),
            'by_entity' => (clone $base())->selectRaw('auditable_type, COUNT(*) as c')
                ->groupBy('auditable_type')->orderBy('c', 'desc')->limit(15)->get()
                ->map(fn ($r) => tap($r, fn ($x) => $x->auditable_type = class_basename((string) $x->auditable_type))),
            'recent' => (clone $base())->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->selectRaw('audit_logs.created_at, COALESCE(users.name, ?) as user, audit_logs.action, audit_logs.auditable_type, audit_logs.ip_address', ['النظام'])
                ->orderByDesc('audit_logs.id')->limit(50)->get()
                ->map(fn ($r) => tap($r, fn ($x) => $x->auditable_type = class_basename((string) $x->auditable_type))),
        ];
    }
}
