<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
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
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * أساس الاحتساب الموحّد للتقارير الثلاثة: **قيمة البضاعة المباعة بعد الخصم**
     * — بلا رسوم توصيل، بلا ضريبة، وبلا أي عمولة موظف أو مسوّق.
     *
     * كانت التقارير الثلاثة تعطي ثلاثة أرقام لليوم نفسه لأن كلًّا منها يجمع من
     * مصدر مختلف (`orders.total` يشمل التوصيل، و`orders.subtotal` قبل الخصم،
     * وبنود الأصناف بعدهما)، فبدا الاختلافُ خللًا. الآن الثلاثة تقرأ الصيغتين
     * أدناه لا غير. نظيرهما في PHP: `OrderItem::goodsSale()/goodsCost()`.
     */
    private const SALE_SQL = '(order_items.qty * order_items.unit_price - order_items.discount)';

    private const COST_SQL = '(order_items.qty * COALESCE(order_items.wholesale_cost_snapshot, product_variants.average_cost, 0))';

    /** المبيعات حسب الزبون: عدد الطلبات وقيمة البضاعة المباعة وربحها لكل زبون. */
    public function salesByCustomer(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);

        $registered = $this->soldGoods($range)
            ->whereNotNull('orders.customer_id')
            ->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id, '.$this->aggregates())
            ->get();

        $names = Customer::whereIn('id', $registered->pluck('customer_id'))->pluck('name', 'id');

        $rows = $registered->map(fn ($r) => $this->summaryRow($r, $names[$r->customer_id] ?? ('#'.$r->customer_id)));

        // الزبون غير المسجَّل لا مُعرِّف له، فيُجمَّع باسمه كما كُتب على الطلب.
        $guests = $this->soldGoods($range)
            ->whereNull('orders.customer_id')
            ->groupBy('orders.customer_name')
            ->selectRaw('orders.customer_name, '.$this->aggregates())
            ->get()
            ->map(fn ($r) => $this->summaryRow($r, $r->customer_name ?: __('زبون نقدي')));

        $rows = $rows->concat($guests)->sortByDesc('sales_total')->values();

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-customer', [__('الزبون'), __('عدد الطلبات'), __('سعر البيع'), __('الربح')],
                $rows->map(fn ($r) => [$r['name'], $r['orders_count'], number_format($r['sales_total'], 2, '.', ''), number_format($r['profit'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_customer', [
            'rows' => $rows,
            'totalOrders' => $this->distinctOrders($range),
            'totalSales' => round($rows->sum('sales_total'), 2),
            'totalProfit' => round($rows->sum('profit'), 2),
        ] + $this->viewMeta($range));
    }

    /** المبيعات حسب المنتج: الكمية المباعة، سعر البيع، متوسط سعر القطعة، الربح. */
    public function salesByProduct(Request $request): View|StreamedResponse
    {
        $range = $this->range($request);

        $rows = $this->soldGoods($range)
            // ربط خارجي عمدًا: البند الحرّ (بلا متغيّر في الكتالوج) كان يسقط من هذا
            // التقرير وحده، فيقلّ مجموعُه عن التقريرين الآخرين بلا سببٍ ظاهر للقارئ.
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.name as product_name, SUM(order_items.qty) as qty_sold, '.$this->aggregates())
            ->orderByDesc('sale_total')
            ->get()
            ->map(function ($r) {
                $qty = (float) $r->qty_sold;
                $sale = (float) $r->sale_total;

                return [
                    'product' => $r->product_name ?: __('بنود بلا صنف مرتبط'),
                    'orders_count' => (int) $r->orders_count,
                    'qty' => $qty,
                    'sale_total' => round($sale, 2),
                    'avg_price' => $qty > 0 ? round($sale / $qty, 2) : 0.0,
                    'profit' => round($sale - (float) $r->cost_total, 2),
                ];
            });

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-product',
                [__('المنتج'), __('عدد الطلبات'), __('الكمية المباعة'), __('سعر البيع'), __('متوسط سعر القطعة'), __('الربح')],
                $rows->map(fn ($r) => [$r['product'], $r['orders_count'], number_format($r['qty'], 2, '.', ''), number_format($r['sale_total'], 2, '.', ''), number_format($r['avg_price'], 2, '.', ''), number_format($r['profit'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_product', [
            'rows' => $rows,
            // مجموع عدد الطلبات ليس جمعَ الأعمدة: الطلب الذي يضمّ صنفين يُعدّ في
            // صفّيهما، فيُحسب المجموع طلباتٍ مختلفة كي يطابق التقريرين الآخرين.
            'totalOrders' => $this->distinctOrders($range),
            'totalQty' => $rows->sum('qty'),
            'totalSales' => round($rows->sum('sale_total'), 2),
            'totalProfit' => round($rows->sum('profit'), 2),
        ] + $this->viewMeta($range));
    }

    /** المبيعات حسب موظف المبيعات: عدد الطلبات وقيمة البضاعة وربحها، مع تفصيل طلبات كل موظف. */
    public function salesByEmployee(Request $request): View|StreamedResponse
    {
        return $this->salesByEarner($request, 'assigned_to', [
            'title' => __('المبيعات حسب موظف المبيعات'),
            'person' => __('الموظف'),
            'unassigned' => __('طلبات بلا موظف مبيعات'),
            'empty' => __('لم يُسجّل أي موظف مبيعات بعد.'),
            'file' => 'sales-by-employee',
            'roles' => ['sales', 'sales_supervisor'],
        ]);
    }

    /** نفس تقرير الموظفين لكن على **المسوّقين** (الطلب مرتبط بمسوّقه لا بموظف مبيعات). */
    public function salesByAffiliate(Request $request): View|StreamedResponse
    {
        return $this->salesByEarner($request, 'affiliate_id', [
            'title' => __('المبيعات حسب المسوّقين'),
            'person' => __('المسوّق'),
            'unassigned' => __('طلبات بلا مسوّق'),
            'empty' => __('لم يُسجّل أي مسوّق مبيعات بعد.'),
            'file' => 'sales-by-affiliate',
            'roles' => ['affiliate'],
        ]);
    }

    /**
     * تقرير المبيعات مجمَّعًا حسب مستفيد الطلب — بعمود الربط فقط (`assigned_to` لموظف
     * المبيعات أو `affiliate_id` للمسوّق)، فالمنطق واحد لا يُكرَّر.
     *
     * يُحتسب من الطلبات المحمَّلة ببنودها لا باستعلام مُجمَّع، لأن الصفحة تعرض تحت كل
     * شخص تفصيلَ طلباته (رقم التتبّع والأصناف والربح) — فيأتي الصفُّ ومجموعُه من
     * الأرقام المعروضة نفسها، ولا يفترق مجموعٌ عن تفصيله.
     *
     * صفّ «بلا مستفيد» مقصود: بدونه كان مجموع الصفحة أقلّ من مبيعات الفترة (طلبات
     * المتجر الإلكتروني وما ينشئه المدير لا مستفيد لها)، فيبدو مخالفًا للتقريرين
     * الآخرين. يسقط الصفُّ تلقائيًا عند فلترة أشخاص بعينهم.
     *
     * @param  array{title: string, person: string, unassigned: string, empty: string, file: string, roles: array<int, string>}  $labels
     */
    private function salesByEarner(Request $request, string $column, array $labels): View|StreamedResponse
    {
        $range = $this->range($request);

        // فلتر الأشخاص: تحديد واحد أو أكثر يقصر التقرير عليهم؛ بلا تحديد = الكل.
        $selected = collect((array) $request->query('users', []))
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        $orders = Order::query()
            ->when($selected !== [], fn ($q) => $q->whereIn('orders.'.$column, $selected))
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->with(['items.variant.product', 'items.variant.attributeValues'])
            ->orderByDesc('created_at')
            ->get()
            // الطلب بلا بنود لا بضاعة فيه — واستبعادُه يبقي عدّ الطلبات مطابقًا
            // للتقريرين الآخرين (وهما يعدّان الطلبات ذات البنود بحكم الربط).
            ->filter(fn (Order $o) => $o->items->isNotEmpty());

        $names = User::whereIn('id', $orders->pluck($column)->filter()->unique())->pluck('name', 'id');

        $rows = $orders
            ->groupBy(fn (Order $o) => (int) $o->{$column})
            ->map(function ($group, $userId) use ($names, $labels) {
                $lines = $group->map(fn (Order $o) => $this->orderLine($o))->values();

                return [
                    'name' => $userId ? ($names[$userId] ?? '#'.$userId) : $labels['unassigned'],
                    'unassigned' => (int) $userId === 0,
                    'orders_count' => $lines->count(),
                    'sales' => round($lines->sum('sale'), 2),
                    'profit' => round($lines->sum('profit'), 2),
                    'orders' => $lines,
                ];
            })
            ->sortByDesc('sales')
            // صفّ «بلا مستفيد» في الذيل مهما كَبُر: التقرير عن الأشخاص أولًا.
            ->sortBy(fn ($r) => $r['unassigned'] ? 1 : 0)
            ->values();

        if ($request->query('export') === 'csv') {
            return $this->csv($labels['file'], [$labels['person'], __('عدد الطلبات'), __('سعر البيع'), __('الربح')],
                $rows->map(fn ($r) => [$r['name'], $r['orders_count'], number_format($r['sales'], 2, '.', ''), number_format($r['profit'], 2, '.', '')]));
        }

        return view('admin.reports.business.sales_by_earner', [
            'rows' => $rows,
            'totalOrders' => $rows->sum('orders_count'),
            'totalSales' => round($rows->sum('sales'), 2),
            'totalProfit' => round($rows->sum('profit'), 2),
            'reportTitle' => $labels['title'],
            'personLabel' => $labels['person'],
            'unassignedLabel' => $labels['unassigned'],
            'emptyDescription' => $labels['empty'],
            // قائمة الفلتر: أصحاب الدور (تظهر حتى قبل تسجيل أي طلب — بناؤها من الطلبات
            // وحدها كان يُفرغها بعد تفريغ البيانات) **مع** من له طلبات فعلًا على العمود،
            // فلا يسقط من غُيِّر دوره لاحقًا. غير مقيَّدة بالفترة كي لا تختفي عند تضييق المدى.
            'people' => User::query()
                ->where(fn ($q) => $q
                    ->whereHas('roles', fn ($r) => $r->whereIn('name', $labels['roles']))
                    ->orWhereIn('id', Order::whereNotNull($column)->distinct()->pluck($column)))
                ->orderBy('name')->get(['id', 'name']),
            'selectedPeople' => $selected,
            'peopleLabel' => $labels['person'],
        ] + $this->viewMeta($range));
    }

    /**
     * أساس التقارير: بنود الطلبات المؤكّدة فأكثر ضمن الفترة، مربوطةً بطلباتها
     * وبمتغيّراتها (ربطًا خارجيًا كي لا يسقط البند الحرّ). كل تقرير يُجمّع فوقه
     * بمفتاحه، فلا يختلف مصدرُ الأرقام من صفحة لأخرى.
     */
    private function soldGoods(DateRange $range): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            // الربط المباشر بالجدول يتخطّى نطاق الحذف الناعم على النموذج.
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$range->from, $range->to]);
    }

    /** الأعمدة المجمَّعة المشتركة: عدد الطلبات المختلفة، سعر البيع، التكلفة. */
    private function aggregates(): string
    {
        return 'COUNT(DISTINCT orders.id) as orders_count, '
            .'SUM('.self::SALE_SQL.') as sale_total, '
            .'SUM('.self::COST_SQL.') as cost_total';
    }

    /** صفّ تقرير من نتيجة مُجمَّعة بأعمدة `aggregates()`. */
    private function summaryRow(object $r, string $name): array
    {
        return [
            'name' => $name,
            'orders_count' => (int) $r->orders_count,
            'sales_total' => round((float) $r->sale_total, 2),
            'profit' => round((float) $r->sale_total - (float) $r->cost_total, 2),
        ];
    }

    /** عدد الطلبات المختلفة في الفترة — مجموعُ الذيل في تقارير لا تُجمَع صفوفُها بالطلب. */
    private function distinctOrders(DateRange $range): int
    {
        return (int) $this->soldGoods($range)->distinct()->count('orders.id');
    }

    /** سطر طلب في تفصيل تقرير الموظف/المسوّق: التتبّع والأصناف والكمية والبيع والربح. */
    private function orderLine(Order $order): array
    {
        $sale = $order->items->sum(fn (OrderItem $i) => $i->goodsSale());
        $cost = $order->items->sum(fn (OrderItem $i) => $i->goodsCost());

        return [
            'number' => $order->number,
            'tracking' => $order->tracking_number,
            'date' => $order->created_at,
            'items' => $order->items->map(fn (OrderItem $i) => [
                'name' => $i->variant?->product?->name ?? __('بند حرّ'),
                'options' => $i->optionsLabel(),
                'qty' => (float) $i->qty,
            ])->all(),
            'qty' => (float) $order->items->sum(fn (OrderItem $i) => (float) $i->qty),
            'sale' => $sale,
            'profit' => $sale - $cost,
        ];
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
