<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * التقارير المالية التشغيلية (Phase 7.1) — للقراءة فقط. كل الأرقام من القيود المُرحّلة/السندات
 * المُرحّلة (لا إجماليات مكرّرة). نطاقات زمنية + تصدير Excel (CSV) + طباعة PDF عبر المتصفّح.
 */
class FinanceReportController extends Controller
{
    public function __construct(private readonly TreasuryService $treasuries) {}

    public function index(): View
    {
        return view('admin.accounting.reports.finance_index', [
            'treasuries' => Treasury::active()->orderBy('type')->orderBy('name')->get()
                ->each(fn (Treasury $t) => $t->current_balance = $this->treasuries->balance($t)),
        ]);
    }

    /** كشف حساب خزينة/بنك: الحركات المُرحّلة برصيد جارٍ. */
    public function treasuryStatement(Treasury $treasury, Request $request): View
    {
        $range = DateRange::resolve($request->query('preset', 'month'), $request->query('from'), $request->query('to'));
        [$from, $to] = $range->bounds();

        $lines = $treasury->glAccount
            ? $treasury->glAccount->lines()
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereBetween('entry_date', [$from, $to]))
                ->with('entry:id,number,entry_date,description')
                ->get()->sortBy(fn ($l) => [$l->entry->entry_date, $l->id])->values()
            : collect();

        // رصيد افتتاحي قبل النطاق.
        $opening = $treasury->glAccount
            ? (float) $treasury->glAccount->lines()
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->where('entry_date', '<', $from))
                ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as b')->value('b')
            : 0.0;

        return view('admin.accounting.reports.treasury_statement', [
            'treasury' => $treasury, 'range' => $range, 'lines' => $lines, 'opening' => round($opening, 2),
            'closing' => $this->treasuries->balance($treasury),
        ]);
    }

    /** تقرير السندات (قبض/صرف/مصروف/إيراد/تحويل) مع فلاتر. */
    public function vouchers(Request $request): View|StreamedResponse
    {
        $range = DateRange::resolve($request->query('preset', 'month'), $request->query('from'), $request->query('to'));
        [$from, $to] = $range->bounds();

        $query = FinancialVoucher::query()->with(['treasury', 'counterAccount'])
            ->whereBetween('voucher_date', [$from, $to])
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('voucher_date');

        if ($request->query('export') === 'csv') {
            return $this->csv('vouchers', ['number', 'kind', 'date', 'status', 'treasury', 'party', 'amount'],
                $query->get()->map(fn ($v) => [$v->number, $v->kind, $v->voucher_date->toDateString(), $v->status, $v->treasury?->name, $v->party_name, $v->amount]));
        }

        return view('admin.accounting.reports.vouchers', ['range' => $range, 'vouchers' => $query->paginate(50)->withQueryString(), 'filters' => $request->only(['kind', 'status'])]);
    }

    /** حركة النقد اليومية (قبض/صرف مُرحّل للخزائن النقدية). */
    public function dailyCash(Request $request): View
    {
        $range = DateRange::resolve($request->query('preset', 'month'), $request->query('from'), $request->query('to'));
        [$from, $to] = $range->bounds();

        $rows = FinancialVoucher::query()->posted()
            ->whereIn('kind', ['receipt', 'payment', 'expense', 'income', 'transfer'])
            ->whereBetween('voucher_date', [$from, $to])
            ->selectRaw('voucher_date as d,
                SUM(CASE WHEN kind IN (\'receipt\',\'income\') THEN amount ELSE 0 END) as inflow,
                SUM(CASE WHEN kind IN (\'payment\',\'expense\') THEN amount ELSE 0 END) as outflow')
            ->groupBy('voucher_date')->orderBy('voucher_date')->get();

        return view('admin.accounting.reports.daily_cash', ['range' => $range, 'rows' => $rows]);
    }

    /** ملخّص المصروفات/الإيرادات الشهري — من القيود المُرحّلة على حسابات المصروف/الإيراد. */
    public function monthlySummary(Request $request): View
    {
        $range = DateRange::resolve($request->query('preset', 'month'), $request->query('from'), $request->query('to'));
        [$from, $to] = $range->bounds();

        $agg = fn (string $type) => DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->where('accounts.type', $type)
            ->selectRaw('accounts.code, accounts.name, '
                .($type === 'expense' ? 'SUM(journal_lines.debit - journal_lines.credit)' : 'SUM(journal_lines.credit - journal_lines.debit)').' as total')
            ->groupBy('accounts.code', 'accounts.name')->havingRaw('total <> 0')->orderByDesc('total')->get();

        return view('admin.accounting.reports.monthly_summary', [
            'range' => $range, 'expenses' => $agg('expense'), 'income' => $agg('revenue'),
        ]);
    }

    private function csv(string $name, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, (array) $row);
            }
            fclose($out);
        }, $name.'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
