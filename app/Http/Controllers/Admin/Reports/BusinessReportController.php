<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * نظام التقارير (المبيعات + الذمم). قراءة فقط، مجاميع مباشرة من البيانات الحيّة، مع فلتر
 * نطاق زمني (من/إلى + اختصارات) وتصدير CSV. المبيعات تُحتسب على الطلبات المؤكّدة فأكثر.
 */
class BusinessReportController extends Controller
{
    /** حالات تُستبعد من المبيعات (غير مؤكّدة أو ملغاة). */
    private const EXCLUDED_STATUSES = ['draft', 'new', 'cancelled'];

    /** المبيعات حسب الزبون: عدد الطلبات ومجموع المبيعات لكل زبون ضمن الفترة. */
    public function salesByCustomer(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);

        $registered = Order::query()
            ->whereNotNull('customer_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('customer_id, COUNT(*) as orders_count, SUM(total) as sales_total')
            ->groupBy('customer_id')
            ->get();

        $names = Customer::whereIn('id', $registered->pluck('customer_id'))->pluck('name', 'id');

        $rows = $registered->map(fn ($r) => [
            'name' => $names[$r->customer_id] ?? ('#'.$r->customer_id),
            'orders_count' => (int) $r->orders_count,
            'sales_total' => (float) $r->sales_total,
        ]);

        $guests = Order::query()
            ->whereNull('customer_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('customer_name, COUNT(*) as orders_count, SUM(total) as sales_total')
            ->groupBy('customer_name')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->customer_name ?: __('زبون نقدي'),
                'orders_count' => (int) $r->orders_count,
                'sales_total' => (float) $r->sales_total,
            ]);

        $rows = $rows->concat($guests)->sortByDesc('sales_total')->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-customer', [__('الزبون'), __('عدد الطلبات'), __('مجموع المبيعات')],
                $rows->map(fn ($r) => [$r['name'], $r['orders_count'], number_format($r['sales_total'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_customer', [
            'rows' => $rows,
            'totalOrders' => $rows->sum('orders_count'),
            'totalSales' => $rows->sum('sales_total'),
        ] + $this->viewMeta($range));
    }

    /** المبيعات حسب المنتج: الكمية المباعة، إجمالي البيع، متوسط سعر القطعة، الربح الإجمالي. */
    public function salesByProduct(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$range->from, $range->to])
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

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-product',
                [__('المنتج'), __('الكمية المباعة'), __('إجمالي البيع'), __('متوسط سعر القطعة'), __('الربح الإجمالي')],
                $rows->map(fn ($r) => [$r['product'], number_format($r['qty'], 2, '.', ''), number_format($r['sale_total'], 2, '.', ''), number_format($r['avg_price'], 2, '.', ''), number_format($r['profit'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_product', [
            'rows' => $rows,
            'totalQty' => $rows->sum('qty'),
            'totalSales' => $rows->sum('sale_total'),
            'totalProfit' => $rows->sum('profit'),
        ] + $this->viewMeta($range));
    }

    /** المبيعات حسب موظف المبيعات: عدد الطلبات وإجمالي المبيعات من غير التوصيل (subtotal). */
    public function salesByEmployee(Request $request): View|StreamedResponse
    {
        return $this->salesByEarner($request, 'assigned_to', [
            'title' => __('المبيعات حسب موظف المبيعات'),
            'person' => __('الموظف'),
            'empty' => __('لم يُسجّل أي موظف مبيعات بعد.'),
            'file' => 'sales-by-employee',
        ]);
    }

    /** نفس تقرير الموظفين لكن على **المسوّقين** (الطلب مرتبط بمسوّقه لا بموظف مبيعات). */
    public function salesByAffiliate(Request $request): View|StreamedResponse
    {
        return $this->salesByEarner($request, 'affiliate_id', [
            'title' => __('المبيعات حسب المسوّقين'),
            'person' => __('المسوّق'),
            'empty' => __('لم يُسجّل أي مسوّق مبيعات بعد.'),
            'file' => 'sales-by-affiliate',
        ]);
    }

    /**
     * تقرير المبيعات مجمَّعًا حسب مستفيد الطلب — بعمود الربط فقط (`assigned_to` لموظف
     * المبيعات أو `affiliate_id` للمسوّق)، فالمنطق واحد لا يُكرَّر. المبالغ من `subtotal`
     * أي **بلا رسوم التوصيل** (لا تخصّنا)، والطلبات الملغاة/المحذوفة مستثناة.
     *
     * @param  array{title: string, person: string, empty: string, file: string}  $labels
     */
    private function salesByEarner(Request $request, string $column, array $labels): View|StreamedResponse
    {
        $range = $this->range($request);

        $rows = Order::query()
            ->whereNotNull($column)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->join('users', 'users.id', '=', 'orders.'.$column)
            ->groupBy('orders.'.$column, 'users.name')
            ->selectRaw('users.name as earner_name, COUNT(*) as orders_count, SUM(orders.subtotal) as sales_ex_shipping')
            ->orderByDesc('sales_ex_shipping')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->earner_name,
                'orders_count' => (int) $r->orders_count,
                'sales' => (float) $r->sales_ex_shipping,
            ]);

        if ($request->query('export') === 'csv') {
            return $this->csv($labels['file'], [$labels['person'], __('عدد الطلبات'), __('إجمالي المبيعات (من غير توصيل)')],
                $rows->map(fn ($r) => [$r['name'], $r['orders_count'], number_format($r['sales'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_earner', [
            'rows' => $rows,
            'totalOrders' => $rows->sum('orders_count'),
            'totalSales' => $rows->sum('sales'),
            'reportTitle' => $labels['title'],
            'personLabel' => $labels['person'],
            'emptyDescription' => $labels['empty'],
        ] + $this->viewMeta($range));
    }

    /** كشف حساب العملاء: المستحق على كل عميل كما في نهاية الفترة (رصيد حسابه في دفتر الأستاذ). */
    public function receivablesCustomers(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);
        $balances = $this->postedAccountBalances($range->to->toDateString());

        $rows = Customer::query()->orderBy('name')->get(['id', 'name', 'gl_account_id'])
            ->map(fn ($c) => [
                'name' => $c->name,
                'due' => round((float) ($balances[$c->gl_account_id] ?? 0), 2),
            ])
            ->sortByDesc('due')
            ->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('receivables-customers', [__('العميل'), __('المبلغ المستحق')],
                $rows->map(fn ($r) => [$r['name'], number_format($r['due'], 2, '.', '')]));
        }

        return view('admin.reports.business.receivables_customers', [
            'rows' => $rows,
            'totalDue' => round($rows->sum('due'), 2),
        ] + $this->viewMeta($range, asOf: true));
    }

    /** كشف حساب الموردين: المستحق لكل مورد كما في نهاية الفترة. */
    public function receivablesSuppliers(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);
        $asOf = $range->to->toDateString();

        $invoiced = PurchaseInvoice::where('status', 'posted')->whereDate('invoice_date', '<=', $asOf)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total) as t')->pluck('t', 'supplier_id');

        $paid = FinancialVoucher::where('kind', 'payment')->where('status', 'posted')->whereDate('voucher_date', '<=', $asOf)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount) as a')->pluck('a', 'supplier_id');

        $rows = Supplier::query()->orderBy('name')->get(['id', 'name', 'opening_balance'])
            ->map(fn ($s) => [
                'name' => $s->name,
                'due' => round((float) $s->opening_balance + ((float) ($invoiced[$s->id] ?? 0) - (float) ($paid[$s->id] ?? 0)), 2),
            ])
            ->sortByDesc('due')
            ->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('receivables-suppliers', [__('المورد'), __('المبلغ المستحق')],
                $rows->map(fn ($r) => [$r['name'], number_format($r['due'], 2, '.', '')]));
        }

        return view('admin.reports.business.receivables_suppliers', [
            'rows' => $rows,
            'totalDue' => round($rows->sum('due'), 2),
        ] + $this->viewMeta($range, asOf: true));
    }

    /** رصيد كل حساب من القيود المُرحّلة حتى تاريخ (مدين − دائن) مفهرسًا بـ account_id. */
    private function postedAccountBalances(string $asOf): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as aid, SUM(journal_lines.debit - journal_lines.credit) as bal')
            ->pluck('bal', 'aid');
    }

    /** النطاق الزمني من الطلب (preset + from/to)، افتراضيًا هذا الشهر. */
    private function range(Request $request): DateRange
    {
        return DateRange::resolve($request->query('range'), $request->query('from'), $request->query('to'));
    }

    /** بيانات مشتركة للواجهة: النطاق واسم الشركة (للترويسة). */
    private function viewMeta(DateRange $range, bool $asOf = false): array
    {
        return [
            'range' => $range,
            'asOf' => $asOf,
            'company' => (string) Settings::get('store.name', 'توفير أونلاين'),
        ];
    }

    /**
     * تنزيل CSV متوافق مع Excel العربي (بادئة BOM). الصفوف مصفوفات قيم مرتّبة كالترويسة.
     *
     * @param  array<int, string>  $head
     * @param  iterable<int, array<int, string|int|float>>  $rows
     */
    private function csv(string $name, array $head, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لعرض العربية في Excel.
            fputcsv($out, $head);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
