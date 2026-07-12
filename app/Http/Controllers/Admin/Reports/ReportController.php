<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * لوحات وتقارير العمليات (Phase 4.7 / ADR-042) — للقراءة فقط، متحكّم رفيع فوق ReportingService.
 * تصدير Excel (CSV UTF-8) وطباعة/PDF (عبر المتصفّح). RTL + عربي/إنجليزي + استجابة للجوّال.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    private function range(Request $request): DateRange
    {
        return DateRange::resolve($request->query('preset'), $request->query('from'), $request->query('to'));
    }

    public function index(Request $request)
    {
        return view('admin.reports.index', ['range' => $this->range($request)]);
    }

    public function executive(Request $request)
    {
        return view('admin.reports.executive', ['range' => $this->range($request), 'data' => $this->reports->executive($this->range($request))]);
    }

    public function sales(Request $request)
    {
        return view('admin.reports.sales', ['range' => $this->range($request), 'data' => $this->reports->sales($this->range($request))]);
    }

    public function orders(Request $request)
    {
        return view('admin.reports.orders', ['range' => $this->range($request), 'data' => $this->reports->orders($this->range($request))]);
    }

    public function delivery(Request $request)
    {
        return view('admin.reports.delivery', ['range' => $this->range($request), 'data' => $this->reports->delivery($this->range($request))]);
    }

    public function finance(Request $request)
    {
        return view('admin.reports.finance', ['range' => $this->range($request), 'data' => $this->reports->finance($this->range($request))]);
    }

    public function salesEmployees(Request $request)
    {
        $rows = $this->search($this->reports->salesEmployees($this->range($request)), $request);
        if ($request->query('export') === 'csv') {
            return $this->csv('sales-employees', ['name', 'orders', 'sales', 'commissions'],
                $rows->map(fn ($r) => [$r->name, $r->orders_count, $r->sales_total, $r->commissions]));
        }

        return view('admin.reports.sales_employees', ['range' => $this->range($request), 'rows' => $rows]);
    }

    public function marketers(Request $request)
    {
        $rows = $this->search($this->reports->marketers($this->range($request)), $request);
        if ($request->query('export') === 'csv') {
            return $this->csv('marketers', ['name', 'orders', 'sales', 'earnings'],
                $rows->map(fn ($r) => [$r->name, $r->orders_count, $r->sales_total, $r->earnings]));
        }

        return view('admin.reports.marketers', ['range' => $this->range($request), 'rows' => $rows]);
    }

    public function products(Request $request)
    {
        $rows = $this->search($this->reports->products($this->range($request)), $request);
        if ($request->query('export') === 'csv') {
            return $this->csv('products', ['name', 'qty', 'revenue', 'returned'],
                $rows->map(fn ($r) => [$r->name, $r->qty, $r->revenue, $r->returned]));
        }

        return view('admin.reports.products', ['range' => $this->range($request), 'rows' => $rows]);
    }

    public function customers(Request $request)
    {
        $data = $this->reports->customers($this->range($request));
        if ($request->query('export') === 'csv') {
            return $this->csv('customers', ['name', 'orders', 'total'],
                $data['top']->map(fn ($r) => [$r->name, $r->orders_count, $r->total]));
        }

        return view('admin.reports.customers', ['range' => $this->range($request), 'data' => $data]);
    }

    public function deliveryCompanies(Request $request)
    {
        $rows = $this->search($this->reports->deliveryCompanies($this->range($request)), $request);
        if ($request->query('export') === 'csv') {
            return $this->csv('delivery-companies', ['name', 'shipments', 'closed', 'returns'],
                $rows->map(fn ($r) => [$r->name, $r->shipments, $r->closed, $r->returns]));
        }

        return view('admin.reports.delivery_companies', ['range' => $this->range($request), 'rows' => $rows]);
    }

    public function returns(Request $request)
    {
        return view('admin.reports.returns', ['range' => $this->range($request), 'data' => $this->reports->returns($this->range($request))]);
    }

    /** بحث/فلترة سريعة بالاسم على التقارير الجدولية. */
    private function search(Collection $rows, Request $request): Collection
    {
        $q = trim((string) $request->query('q'));
        if ($q === '') {
            return $rows;
        }

        return $rows->filter(fn ($r) => mb_stripos((string) ($r->name ?? ''), $q) !== false)->values();
    }

    /** تصدير Excel (CSV بترميز UTF-8 مع BOM لعرض العربية). */
    private function csv(string $name, array $headers, Collection $rows): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, (array) $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
