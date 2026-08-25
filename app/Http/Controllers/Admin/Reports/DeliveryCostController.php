<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Reporting\Services\DeliveryCostReportService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تقرير تكلفة التوصيل — للمطابقة مع كشف شركة التوصيل.
 *
 * متحكّم رفيع فوق الخدمة: لا حساب هنا. والتقرير **لا يعرض مبيعات ولا ربحًا**
 * عمدًا — غرضه رقمٌ واحد قابل للمطابقة مع فاتورة الشركة، وخلطُ المبيعات به
 * يُفقده هذا الغرض.
 */
class DeliveryCostController extends Controller
{
    public function __construct(private readonly DeliveryCostReportService $service) {}

    public function index(Request $request): View|StreamedResponse
    {
        $this->authorize('reports.delivery_cost.view');

        $range = DateRange::resolve($request->query('range'), $request->query('from'), $request->query('to'));
        $cityId = $request->integer('city_id') ?: null;
        $areaId = $request->integer('area_id') ?: null;

        $rows = $this->service->rows($range, $cityId, $areaId);

        if ($request->query('export') === 'csv') {
            return $this->csv($rows, $range);
        }

        return view('admin.reports.business.delivery_cost', [
            'rows' => $rows,
            'totals' => $this->service->totals($rows),
            'range' => $range,
            'cityId' => $cityId,
            'areaId' => $areaId,
            'cities' => City::orderBy('name')->get(['id', 'name']),
            // مناطق المدينة المختارة وحدها: قائمةُ كل مناطق البلاد غير قابلة
            // للاستعمال، واختيار منطقةٍ من مدينةٍ أخرى يُنتج تقريرًا فارغًا.
            'areas' => $cityId
                ? Area::where('city_id', $cityId)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'company' => (string) Settings::get('store.name', 'توفير أونلاين'),
        ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function csv($rows, DateRange $range): StreamedResponse
    {
        $head = [__('المدينة'), __('المنطقة'), __('الطرود'), __('التكلفة'), __('متوسط الطرد'), __('بلا رسم')];

        $name = 'delivery-cost-'.$range->from->toDateString().'_'.$range->to->toDateString().'.csv';

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لعرض العربية في Excel.
            fputcsv($out, $head);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['city'], $r['area'], $r['parcels'],
                    number_format($r['cost'], 2, '.', ''),
                    number_format($r['avg'], 2, '.', ''),
                    $r['unpriced'],
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, [
                __('الإجمالي'), '', $rows->sum('parcels'),
                number_format((float) $rows->sum('cost'), 2, '.', ''),
            ]);

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
