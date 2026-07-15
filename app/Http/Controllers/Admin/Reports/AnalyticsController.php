<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\AnalyticsService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * وحدة «التقارير والتحليلات» — للقراءة فقط، متحكّم رفيع فوق AnalyticsService.
 * كل فئة صفحة مستقلّة (KPIs + رسوم Chart.js + جداول) مع فلاتر موحّدة وتصدير CSV/طباعة.
 * الصلاحيات تُفرض على مستوى المسار (can:reports.<category>.view).
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    private function range(Request $request): DateRange
    {
        return DateRange::resolve($request->query('preset'), $request->query('from'), $request->query('to'));
    }

    public function index(Request $request): View
    {
        return view('admin.reports.analytics.index', ['range' => $this->range($request)]);
    }

    public function dashboard(Request $request): View
    {
        $range = $this->range($request);

        return view('admin.reports.analytics.dashboard', [
            'range' => $range,
            'd' => $this->analytics->dashboard($range),
        ]);
    }

    public function sales(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->sales($range);

        if ($request->query('export') === 'csv') {
            return $this->csv('sales-by-product', ['المنتج', 'الكمية', 'الإيراد', 'الربح'],
                $data['by_product']->map(fn ($r) => [$r->name, $r->qty, $r->revenue, $r->profit]));
        }

        return view('admin.reports.analytics.sales', ['range' => $range, 'data' => $data]);
    }

    public function customers(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->customers($range);

        if ($request->query('export') === 'csv') {
            return $this->csv('top-customers', ['العميل', 'الطلبات', 'الإجمالي'],
                $data['top']->map(fn ($r) => [$r->name, $r->orders_count, $r->total]));
        }

        return view('admin.reports.analytics.customers', ['range' => $range, 'data' => $data]);
    }

    public function products(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->products($range);

        if ($request->query('export') === 'csv') {
            return $this->csv('best-products', ['المنتج', 'الكمية', 'الإيراد', 'الربح', 'المرتجع'],
                $data['best']->map(fn ($r) => [$r->name, $r->qty, $r->revenue, $r->profit, $r->returned]));
        }

        return view('admin.reports.analytics.products', ['range' => $range, 'data' => $data]);
    }

    public function inventory(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->inventory($range);

        if ($request->query('export') === 'csv') {
            return $this->csv('low-stock', ['المنتج', 'SKU', 'المتوفّر', 'حد الطلب'],
                $data['low_stock']->map(fn ($r) => [$r->name, $r->sku, $r->on_hand, $r->reorder_level]));
        }

        return view('admin.reports.analytics.inventory', ['range' => $range, 'data' => $data]);
    }

    public function shipping(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->shipping($range);

        if ($request->query('export') === 'csv') {
            return $this->csv('shipping-by-city', ['المدينة', 'الشحنات', 'التكلفة'],
                $data['by_city']->map(fn ($r) => [$r->name, $r->c, $r->cost]));
        }

        return view('admin.reports.analytics.shipping', ['range' => $range, 'data' => $data]);
    }

    public function financial(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->financial($range);

        if ($request->query('export') === 'csv') {
            return $this->csv('daily-profit', ['التاريخ', 'الإيراد', 'التكلفة', 'الربح'],
                $data['daily_profit']->map(fn ($r) => [$r->d, $this->num($r->revenue), $this->num($r->cost), $r->profit]));
        }

        return view('admin.reports.analytics.financial', ['range' => $range, 'data' => $data]);
    }

    public function employees(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->earners($range, 'employee', 'assigned_to');

        if ($request->query('export') === 'csv') {
            return $this->csv('employees', ['الموظف', 'الطلبات', 'الإيراد', 'الربح', 'العمولة'],
                $data['rows']->map(fn ($r) => [$r->name, $r->orders_count, $r->revenue, $r->profit, $r->commission]));
        }

        return view('admin.reports.analytics.employees', [
            'range' => $range, 'data' => $data,
            'title' => __('تقارير الموظفين'), 'roleLabel' => __('الموظف'),
        ]);
    }

    public function marketers(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->earners($range, 'marketer', 'affiliate_id');

        if ($request->query('export') === 'csv') {
            return $this->csv('marketers', ['المسوّق', 'الطلبات', 'الإيراد', 'الربح', 'العمولة'],
                $data['rows']->map(fn ($r) => [$r->name, $r->orders_count, $r->revenue, $r->profit, $r->commission]));
        }

        return view('admin.reports.analytics.employees', [
            'range' => $range, 'data' => $data,
            'title' => __('تقارير المسوّقين'), 'roleLabel' => __('المسوّق'),
        ]);
    }

    public function marketing(Request $request): View
    {
        $range = $this->range($request);

        return view('admin.reports.analytics.marketing', [
            'range' => $range, 'data' => $this->analytics->marketing($range),
        ]);
    }

    public function support(Request $request): View
    {
        // لا يوجد نموذج تذاكر/خدمة عملاء بعد — صفحة بحالة فارغة صريحة (هيكل جاهز).
        return view('admin.reports.analytics.support', ['range' => $this->range($request)]);
    }

    public function audit(Request $request): StreamedResponse|View
    {
        $range = $this->range($request);
        $data = $this->analytics->audit($range);

        if ($request->query('export') === 'csv') {
            return $this->csv('audit', ['التاريخ', 'المستخدم', 'الإجراء', 'الكيان', 'IP'],
                $data['recent']->map(fn ($r) => [$r->created_at, $r->user, $r->action, $r->auditable_type, $r->ip_address]));
        }

        return view('admin.reports.analytics.audit', ['range' => $range, 'data' => $data]);
    }

    private function num(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    /** تصدير Excel (CSV بترميز UTF-8 مع BOM لعرض العربية). */
    private function csv(string $name, array $headers, Collection $rows): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, (array) $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
