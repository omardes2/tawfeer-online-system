<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
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
        private readonly TreasuryService $treasuries,
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

        return view('admin.dashboard.index', array_merge([
            'todaySales' => $todayKpis['sales']['total'],
            'todayOrders' => $todayKpis['sales']['orders'],
            'month' => $monthKpis,
            'salesDaily' => $this->reports->sales($month)['daily'],
            'deliveryByStatus' => $deliveryByStatus,
            'latestOrders' => $latestOrders,
            'warehouse' => $mainWarehouse ? $this->warehouses->dashboard($mainWarehouse) : null,
        ], ['finance' => $this->financeSection($from, $to)]));
    }

    /**
     * قسم المالية بلوحة التحكّم (Phase 7.1) — كل الأرقام من السندات المُرحّلة/القيود.
     *
     * @return array<string, mixed>
     */
    private function financeSection(string $from, string $to): array
    {
        $today = now()->toDateString();
        $cashboxTotal = Treasury::type('cash')->active()->get()->sum(fn (Treasury $t) => $this->treasuries->balance($t));
        $bankTotal = Treasury::type('bank')->active()->get()->sum(fn (Treasury $t) => $this->treasuries->balance($t));

        $sumPosted = fn (array $kinds, string $fromDate, string $toDate) => (float) FinancialVoucher::posted()
            ->whereIn('kind', $kinds)->whereBetween('voucher_date', [$fromDate, $toDate])->sum('amount');

        return [
            'cashbox_total' => round($cashboxTotal, 2),
            'bank_total' => round($bankTotal, 2),
            'today_receipts' => $sumPosted(['receipt', 'income'], $today, $today),
            'today_payments' => $sumPosted(['payment', 'expense'], $today, $today),
            'monthly_expenses' => $sumPosted(['expense'], $from, $to),
            'monthly_income' => $sumPosted(['income'], $from, $to),
            'unposted' => (int) FinancialVoucher::whereIn('status', ['draft', 'approved'])->count(),
            'reversed' => (int) FinancialVoucher::where('status', 'reversed')->count(),
            'recent' => FinancialVoucher::posted()->with('treasury')->latest('posted_at')->limit(8)->get(),
            'cash_daily' => FinancialVoucher::posted()
                ->whereIn('kind', ['receipt', 'payment', 'expense', 'income'])
                ->whereBetween('voucher_date', [$from, $to])
                ->selectRaw('voucher_date as d,
                    SUM(CASE WHEN kind IN (\'receipt\',\'income\') THEN amount ELSE 0 END) as inflow,
                    SUM(CASE WHEN kind IN (\'payment\',\'expense\') THEN amount ELSE 0 END) as outflow')
                ->groupBy('voucher_date')->orderBy('voucher_date')->get(),
        ];
    }
}
