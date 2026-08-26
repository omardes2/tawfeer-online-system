<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Reporting\Services\ProfitLossService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * قائمة الأرباح والخسائر — متحكّم رفيع فوق `ProfitLossService`.
 *
 * لا حساب هنا: الشاشة والتصدير يقرآن الصفوف نفسها، فلا يفترق ملفُ Excel عن
 * الصفحة التي صُدِّر منها.
 */
class ProfitLossController extends Controller
{
    public function __construct(private readonly ProfitLossService $service) {}

    public function index(Request $request): View|StreamedResponse
    {
        $this->authorize('reports.profit_loss.view');

        $range = DateRange::resolve($request->query('range'), $request->query('from'), $request->query('to'));

        $report = $this->service->report($range);

        if ($request->query('export') === 'csv') {
            return $this->csv($report, $range);
        }

        return view('admin.reports.business.profit_loss', [
            'report' => $report,
            'range' => $range,
            'company' => (string) Settings::get('store.name', 'توفير أونلاين'),
        ]);
    }

    /**
     * التصدير يتبع ترتيب القائمة نفسه: إيراد ثم تكلفة ثم مجمل ربح ثم مصروف ثم صافي.
     *
     * @param  array<string, mixed>  $report
     */
    private function csv(array $report, DateRange $range): StreamedResponse
    {
        $rows = $this->lines($report);

        $name = 'profit-loss-'.$range->fromString().'_'.$range->toString().'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لعرض العربية في Excel.
            fputcsv($out, [__('البيان'), __('الإجمالي')]);

            foreach ($rows as $row) {
                fputcsv($out, [$row[0], number_format($row[1], 2, '.', '')]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array{0: string, 1: float}>
     */
    private function lines(array $report): array
    {
        $lines = [
            [__('مبيعات الموظفين'), $report['revenue']['staff']],
            [__('المبيعات المباشرة'), $report['revenue']['direct']],
            [__('مبيعات المسوّقين'), $report['revenue']['affiliates']],
            [__('مبيعات المتجر'), $report['revenue']['store']],
            [__('إجمالي الإيرادات'), $report['revenue']['total']],
            [__('تكلفة البضاعة المباعة'), $report['cogs']],
            [__('مجمل الربح'), $report['gross_profit']],
            [__('الإعلانات'), $report['expenses']['ads']],
            [__('عمولات المبيعات والتسويق'), $report['expenses']['commissions']],
            [__('الرواتب والأجور'), $report['expenses']['payroll']],
            [__('مكافأة نهاية الخدمة'), $report['expenses']['end_of_service']],
        ];

        foreach ($report['expenses']['categories'] as $category) {
            $lines[] = [$category['name'], $category['total']];
        }

        $lines[] = [__('إجمالي المصاريف'), $report['expenses']['total']];
        $lines[] = [__('صافي الدخل'), $report['net_income']];

        return $lines;
    }
}
