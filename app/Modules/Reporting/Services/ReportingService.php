<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Support\DateRange;
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
    /** @return array<string, float|int> */
    public function executive(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $orders = DB::table('orders')->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled');

        return [
            'orders' => (int) (clone $orders)->count(),
            'sales_total' => $this->money((clone $orders)->sum('total')),
            'delivered' => (int) DB::table('orders')->whereBetween('delivered_at', [$from, $to])->count(),
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
        $base = fn () => DB::table('orders')->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled');

        return [
            'total' => $this->money($base()->sum('total')),
            'count' => (int) $base()->count(),
            'by_status' => $base()->selectRaw('status, COUNT(*) as c, SUM(total) as t')->groupBy('status')->get(),
            'by_channel' => $base()->selectRaw('channel, COUNT(*) as c, SUM(total) as t')->groupBy('channel')->get(),
            'daily' => $base()->selectRaw('DATE(created_at) as d, SUM(total) as t')->groupBy('d')->orderBy('d')->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function orders(DateRange $range): array
    {
        [$from, $to] = $range->bounds();
        $base = fn () => DB::table('orders')->whereBetween('created_at', [$from, $to]);
        $count = (int) $base()->where('status', '!=', 'cancelled')->count();
        $total = $this->money($base()->where('status', '!=', 'cancelled')->sum('total'));

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

        return DB::table('orders')
            ->join('users', 'orders.assigned_to', '=', 'users.id')
            ->whereBetween('orders.created_at', [$from, $to])->where('orders.status', '!=', 'cancelled')
            ->whereNotNull('orders.assigned_to')
            ->selectRaw('users.id, users.name, COUNT(*) as orders_count, SUM(orders.total) as sales_total')
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

        return DB::table('orders')
            ->join('users', 'orders.affiliate_id', '=', 'users.id')
            ->whereBetween('orders.created_at', [$from, $to])->where('orders.status', '!=', 'cancelled')
            ->whereNotNull('orders.affiliate_id')
            ->selectRaw('users.id, users.name, COUNT(*) as orders_count, SUM(orders.total) as sales_total')
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

    /** أداء المنتجات (الأعلى مبيعًا). @return Collection<int, object> */
    public function products(DateRange $range, int $limit = 20): Collection
    {
        [$from, $to] = $range->bounds();

        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
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

        $top = DB::table('orders')->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, MAX(customer_name) as name, COUNT(*) as orders_count, SUM(total) as total')
            ->groupBy('customer_id')->orderByDesc('total')->limit($limit)->get()
            ->map(function ($row) {
                $row->total = $this->money($row->total);

                return $row;
            });

        $repeat = DB::table('orders')->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')
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

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
