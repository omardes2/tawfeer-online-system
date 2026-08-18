<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Reporting\Services\ProductDecisionService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * لوحة قرار الصنف — ماذا يربح فعلًا، ومتى ينفد.
 *
 * متحكّم رفيع فوق `ProductDecisionService`: لا حساب هنا، فالأرقام قرارُ عملٍ
 * يُختبَر في الخدمة لا في الشاشة.
 */
class ProductDecisionController extends Controller
{
    public function __construct(private readonly ProductDecisionService $service) {}

    public function index(Request $request): View|StreamedResponse
    {
        $this->authorize('reports.sales_summary.view');

        $range = DateRange::resolve($request->query('range'), $request->query('from'), $request->query('to'));
        $rows = $this->service->board($range);

        if ($request->query('export') === 'csv') {
            return $this->csv($rows);
        }

        return view('admin.reports.business.product_decision', [
            'rows' => $rows,
            'range' => $range,
            'asOf' => false,
            'company' => (string) Settings::get('store.name', 'توفير أونلاين'),
            'plan' => $this->service->planningSettings(),
            'totals' => [
                'sales' => round($rows->sum('sales'), 2),
                'ad_spend' => round($rows->sum('ad_spend'), 2),
                'delivery' => round($rows->sum('delivery_cost'), 2),
                'net_profit' => round($rows->sum('net_profit'), 2),
            ],
        ]);
    }

    /**
     * ضبط مهلة التوريد ومخزون الأمان.
     *
     * رقمان يحكمان كل تنبيهات النفاد والكميات المقترحة، فيبقيان في الإعدادات
     * لا في الكود: مهلة الصين ليست مهلة مورّدٍ محلّي، وهي تتغيّر بالمواسم.
     */
    public function updatePlanning(Request $request): RedirectResponse
    {
        $this->authorize('reports.ad_budget.manage');

        $data = $request->validate([
            'lead_time_days' => ['required', 'integer', 'min:1', 'max:365'],
            'safety_days' => ['required', 'integer', 'min:0', 'max:180'],
        ]);

        Settings::set('inventory.lead_time_days', (string) $data['lead_time_days'], 'inventory', 'integer');
        Settings::set('inventory.safety_days', (string) $data['safety_days'], 'inventory', 'integer');

        return back()->with('success', __('حُدّثت إعدادات التخطيط.'));
    }

    private function csv($rows): StreamedResponse
    {
        $head = [
            __('الصنف'), __('SKU'), __('الطلبات'), __('الكمية المباعة'), __('المرتجع'), __('نسبة الارتجاع %'),
            __('المبيعات'), __('تكلفة البضاعة'), __('صرف الإعلان'), __('تكلفة التوصيل'),
            __('صافي الربح'), __('الهامش %'),
            __('المتوفّر'), __('البيع اليومي'), __('أيام التغطية'), __('في الطريق'), __('الكمية المقترحة'), __('الحكم'),
        ];

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لعرض العربية في Excel.
            fputcsv($out, $head);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['product'], $r['sku'], $r['orders_count'], $r['qty_sold'], $r['returned_qty'], $r['return_rate'],
                    number_format($r['sales'], 2, '.', ''), number_format($r['cogs'], 2, '.', ''),
                    number_format($r['ad_spend'], 2, '.', ''), number_format($r['delivery_cost'], 2, '.', ''),
                    number_format($r['net_profit'], 2, '.', ''), $r['margin_pct'],
                    $r['available'], $r['velocity'], $r['days_of_cover'], $r['incoming'], $r['suggested_qty'],
                    $r['verdict']['label'],
                ]);
            }
            fclose($out);
        }, 'product-decision-board.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
