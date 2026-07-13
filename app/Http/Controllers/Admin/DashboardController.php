<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * لوحة التحكّم التنفيذية (Production) — للقراءة فقط، متحكّم رفيع فوق الخدمات القائمة
 * (ReportingService/WarehouseService). لا تكرار منطق أعمال. RTL + عربي/إنجليزي + استجابة.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportingService $reports,
        private readonly WarehouseService $warehouses,
    ) {}

    public function index(): View
    {
        $today = DateRange::resolve('day');
        $month = DateRange::resolve('month');

        $todayKpis = $this->reports->kpis($today);
        $monthKpis = $this->reports->kpis($month);

        [$from, $to] = $month->bounds();
        $deliveryByStatus = DB::table('shipments')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('delivery_status, COUNT(*) as c')
            ->groupBy('delivery_status')->pluck('c', 'delivery_status');

        $latestOrders = Order::query()
            ->whereNotNull('number')
            ->latest('id')->limit(8)
            ->get(['id', 'number', 'total', 'status', 'created_at']);

        $mainWarehouse = Warehouse::where('is_default', true)->first();

        return view('admin.dashboard.index', [
            'todaySales' => $todayKpis['sales']['total'],
            'todayOrders' => $todayKpis['sales']['orders'],
            'month' => $monthKpis,
            'salesDaily' => $this->reports->sales($month)['daily'],
            'deliveryByStatus' => $deliveryByStatus,
            'latestOrders' => $latestOrders,
            'warehouse' => $mainWarehouse ? $this->warehouses->dashboard($mainWarehouse) : null,
        ]);
    }
}
