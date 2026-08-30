<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
        private readonly CommissionService $commissions,
    ) {}

    /**
     * لوحة واحدة لكل دور: من يتابع الفريق يرى النظرة العامة، ومن يبيع لنفسه
     * (موظف مبيعات أو مسوّق) يرى شاشته الشخصية — أرقامه هو لا أرقام الشركة.
     */
    public function index(): View
    {
        $user = auth()->user();

        $seesTeam = Gate::allows('commissions.view_team')
            || Gate::allows('reports.sales_summary.view')
            || Gate::allows('accounting.reports.view');

        return $user->hasAnyRole(['sales', 'affiliate']) && ! $seesTeam
            ? $this->personal($user)
            : $this->overview();
    }

    /** النظرة العامة — للمدير والمحاسب ومن يتابع أداء الفريق. */
    private function overview(): View
    {
        $today = DateRange::resolve('day');
        $month = DateRange::resolve('month');

        $todayKpis = $this->reports->kpis($today);
        $monthKpis = $this->reports->kpis($month);

        [$from, $to] = $month->bounds();
        // شحنة طلبٍ محذوف لا تُعدّ — وإلا ظهرت شحنات بلا طلبات في اللوحة.
        $deliveryByStatus = DB::table('shipments')
            ->join('orders', 'shipments.order_id', '=', 'orders.id')
            ->whereNull('orders.deleted_at')
            ->whereBetween('shipments.created_at', [$from, $to])
            ->selectRaw('shipments.delivery_status, COUNT(*) as c')
            ->groupBy('shipments.delivery_status')->pluck('c', 'delivery_status');

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
            // مبيعات اليوم مفصولةً: موظفون ومسوّقون، بلا رسوم التوصيل.
            'todayByEarner' => $this->reports->todaySalesByEarnerType(),
            // اثنا عشر شهرًا كاملة للسنة الجارية — الفارغة منها تظهر صفرًا.
            'monthlySales' => $this->reports->monthlySales((int) today()->year),
            'chartYear' => (int) today()->year,
            'deliveryByStatus' => $deliveryByStatus,
            'latestOrders' => $latestOrders,
            'warehouse' => $mainWarehouse ? $this->warehouses->dashboard($mainWarehouse) : null,
            'byStatus' => Order::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            // لوحتا الأداء اليومي: موظفو المبيعات والمسوّقون، كلٌّ في جدوله.
            'salesBoard' => $this->reports->earnerBoard('assigned_to', ['sales', 'sales_supervisor']),
            'affiliateBoard' => $this->reports->earnerBoard('affiliate_id', ['affiliate']),
            /**
             * ما على الشركة للمسوّقين والموظفين الآن — **بعد طرح ما صُرف**.
             *
             * كان يُجمع `eligible` و`approved` وحدهما. وصرفُ الدفعة لا يُحوّل
             * البنود إلى `paid` — الدفعة مبلغٌ على الحساب لا تُقابَل ببنودٍ
             * بعينها — فبقي الرقم كما كان بعد الصرف وكأن المال لم يخرج.
             *
             * فصار من `outstandingTotal`: المستحقّ ناقص ما صُرف وما هو في طريقه،
             * وهو تعريف كشف حساب المستفيد نفسه مطبَّقًا على الجميع.
             *
             * و`pending` تبقى **خارجه**: عمولةٌ على طلبٍ سُلّم ولم يصل مالُه من
             * شركة التوصيل بعد — لم تُستحقّ، فعدُّها ديْنًا قائمًا يُظهر على
             * الشركة ما لا تدين به اليوم. وتُعرض بجانبه كي لا تختفي.
             */
            'pendingCommissions' => Gate::allows('commissions.view_team')
                ? $this->commissions->outstandingTotal()
                : null,
            'notYetDueCommissions' => Gate::allows('commissions.view_team')
                ? (float) CommissionEntry::where('state', 'pending')->sum('amount')
                : null,
        ], ['finance' => $this->financeSection($from, $to)]));
    }

    /**
     * الشاشة الشخصية لموظف المبيعات/المسوّق: طلباته، وما يستحقّه، وما يحتاج متابعته.
     * لا أرصدة خزائن ولا أداء زملاء — لا يمرّ على أرقام لا تعنيه ولا يملك صلاحيتها.
     */
    private function personal(User $user): View
    {
        $isAffiliate = $user->hasRole('affiliate');
        $earnerType = $isAffiliate ? 'affiliate' : 'sales';
        $ownerColumn = $isAffiliate ? 'affiliate_id' : 'assigned_to';

        $mine = fn () => Order::where($ownerColumn, $user->id)->whereNotNull('number');

        [$monthFrom, $monthTo] = DateRange::resolve('month')->bounds();

        // المبيعات بلا رسوم التوصيل — وهو الأساس الذي تُحتسب عليه العمولة.
        $netSales = fn ($query) => (float) $query
            ->selectRaw('COALESCE(SUM(total - shipping_total), 0) as v')->value('v');

        return view('admin.dashboard.personal', [
            'earnerType' => $earnerType,
            'todayOrders' => (clone $mine())->whereDate('created_at', today())->count(),
            'todaySales' => $netSales((clone $mine())->whereDate('created_at', today())),
            'monthOrders' => (clone $mine())->whereBetween('created_at', [$monthFrom, $monthTo])->count(),
            'monthSales' => $netSales((clone $mine())->whereBetween('created_at', [$monthFrom, $monthTo])),
            'balance' => $this->commissions->balance($user->id, $earnerType),
            'statement' => $this->commissions->statement($user->id, $earnerType),
            'latestOrders' => (clone $mine())->latest('id')->limit(8)
                ->get(['id', 'uuid', 'number', 'total', 'status', 'created_at']),
            'needsAttention' => (clone $mine())
                ->whereIn('status', self::NEEDS_ATTENTION)
                ->latest('id')->limit(5)
                ->get(['id', 'uuid', 'number', 'total', 'status', 'created_at']),
        ]);
    }

    /** حالات تعني أن الطلب متوقّف بانتظار تصرّف من صاحبه. */
    private const NEEDS_ATTENTION = [
        'awaiting_contact', 'awaiting_confirmation', 'customer_unavailable', 'delayed', 'delivery_failed',
    ];

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
