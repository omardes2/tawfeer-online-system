<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * لوحة مؤشّرات الأداء الموسّعة (Phase 6 / ADR-047) — للقراءة فقط، متحكّم رفيع فوق ReportingService.
 * يوم/أسبوع/شهر/مخصّص. RTL + عربي/إنجليزي + استجابة للجوّال.
 */
class KpiController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    public function index(Request $request): View
    {
        $range = DateRange::resolve($request->query('preset'), $request->query('from'), $request->query('to'));

        return view('admin.reports.kpis', [
            'range' => $range,
            'kpis' => $this->reports->kpis($range),
        ]);
    }
}
