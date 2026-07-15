<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Crm\Models\Customer;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * نظام التقارير (المبيعات + الذمم). قراءة فقط، مجاميع مباشرة من البيانات الحيّة.
 * تُحتسب المبيعات على الطلبات المؤكّدة فأكثر (تُستبعد المسوّدات والملغاة).
 */
class BusinessReportController extends Controller
{
    /** حالات تُستبعد من المبيعات (غير مؤكّدة أو ملغاة). */
    private const EXCLUDED_STATUSES = ['draft', 'new', 'cancelled'];

    /** المبيعات حسب الزبون: عدد الطلبات ومجموع المبيعات لكل زبون. */
    public function salesByCustomer(): View
    {
        $registered = Order::query()
            ->whereNotNull('customer_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->selectRaw('customer_id, COUNT(*) as orders_count, SUM(total) as sales_total')
            ->groupBy('customer_id')
            ->get();

        $names = Customer::whereIn('id', $registered->pluck('customer_id'))->pluck('name', 'id');

        $rows = $registered->map(fn ($r) => [
            'name' => $names[$r->customer_id] ?? ('#'.$r->customer_id),
            'orders_count' => (int) $r->orders_count,
            'sales_total' => (float) $r->sales_total,
        ]);

        // طلبات بلا زبون مسجّل (نقدي/ضيف) مجمّعة بالاسم.
        $guests = Order::query()
            ->whereNull('customer_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->selectRaw('customer_name, COUNT(*) as orders_count, SUM(total) as sales_total')
            ->groupBy('customer_name')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->customer_name ?: __('زبون نقدي'),
                'orders_count' => (int) $r->orders_count,
                'sales_total' => (float) $r->sales_total,
            ]);

        $rows = $rows->concat($guests)->sortByDesc('sales_total')->values();

        return view('admin.reports.business.sales_by_customer', [
            'rows' => $rows,
            'totalOrders' => $rows->sum('orders_count'),
            'totalSales' => $rows->sum('sales_total'),
        ]);
    }

    /** المبيعات حسب المنتج: الكمية المباعة، إجمالي البيع، متوسط سعر القطعة، الربح الإجمالي. */
    public function salesByProduct(): View
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.name as product_name,
                SUM(order_items.qty) as qty_sold,
                SUM(order_items.qty * order_items.unit_price - order_items.discount) as sale_total,
                SUM(order_items.qty * COALESCE(order_items.wholesale_cost_snapshot, product_variants.average_cost, 0)) as cost_total')
            ->orderByDesc('sale_total')
            ->get()
            ->map(function ($r) {
                $qty = (float) $r->qty_sold;
                $sale = (float) $r->sale_total;
                $cost = (float) $r->cost_total;

                return [
                    'product' => $r->product_name,
                    'qty' => $qty,
                    'sale_total' => round($sale, 2),
                    'avg_price' => $qty > 0 ? round($sale / $qty, 2) : 0.0,
                    'profit' => round($sale - $cost, 2),
                ];
            });

        return view('admin.reports.business.sales_by_product', [
            'rows' => $rows,
            'totalQty' => $rows->sum('qty'),
            'totalSales' => $rows->sum('sale_total'),
            'totalProfit' => $rows->sum('profit'),
        ]);
    }

    /** المبيعات حسب موظف المبيعات: عدد الطلبات وإجمالي المبيعات من غير التوصيل (subtotal). */
    public function salesByEmployee(): View
    {
        $rows = Order::query()
            ->whereNotNull('assigned_to')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->join('users', 'users.id', '=', 'orders.assigned_to')
            ->groupBy('orders.assigned_to', 'users.name')
            ->selectRaw('users.name as emp_name, COUNT(*) as orders_count, SUM(orders.subtotal) as sales_ex_shipping')
            ->orderByDesc('sales_ex_shipping')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->emp_name,
                'orders_count' => (int) $r->orders_count,
                'sales' => (float) $r->sales_ex_shipping,
            ]);

        return view('admin.reports.business.sales_by_employee', [
            'rows' => $rows,
            'totalOrders' => $rows->sum('orders_count'),
            'totalSales' => $rows->sum('sales'),
        ]);
    }

    /** كشف حساب العملاء: المستحق على كل عميل (رصيد حسابه الفرعي في دفتر الأستاذ). */
    public function receivablesCustomers(): View
    {
        $balances = $this->postedAccountBalances();

        $rows = Customer::query()->orderBy('name')->get(['id', 'name', 'gl_account_id'])
            ->map(fn ($c) => [
                'name' => $c->name,
                'due' => round((float) ($balances[$c->gl_account_id] ?? 0), 2),
            ])
            ->sortByDesc('due')
            ->values();

        return view('admin.reports.business.receivables_customers', [
            'rows' => $rows,
            'totalDue' => round($rows->sum('due'), 2),
        ]);
    }

    /** كشف حساب الموردين: المستحق لكل مورد (رصيد افتتاحي + فواتير مُرحّلة − مدفوعات). */
    public function receivablesSuppliers(): View
    {
        $invoiced = PurchaseInvoice::where('status', 'posted')
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total) as t')->pluck('t', 'supplier_id');

        $paid = FinancialVoucher::where('kind', 'payment')->where('status', 'posted')
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount) as a')->pluck('a', 'supplier_id');

        $rows = Supplier::query()->orderBy('name')->get(['id', 'name', 'opening_balance'])
            ->map(fn ($s) => [
                'name' => $s->name,
                'due' => round((float) $s->opening_balance + ((float) ($invoiced[$s->id] ?? 0) - (float) ($paid[$s->id] ?? 0)), 2),
            ])
            ->sortByDesc('due')
            ->values();

        return view('admin.reports.business.receivables_suppliers', [
            'rows' => $rows,
            'totalDue' => round($rows->sum('due'), 2),
        ]);
    }

    /** رصيد كل حساب من القيود المُرحّلة (مدين − دائن) مفهرسًا بـ account_id. */
    private function postedAccountBalances(): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as aid, SUM(journal_lines.debit - journal_lines.credit) as bal')
            ->pluck('bal', 'aid');
    }
}
