<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Hr\Services\EndOfServiceService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * قائمة الأرباح والخسائر.
 *
 * ## من أين يأتي كلّ رقم
 *
 * | البند | المصدر |
 * |---|---|
 * | المبيعات | بنود الفواتير — لا `orders.total` |
 * | تكلفة البضاعة | `wholesale_cost_snapshot` المُجمَّدة وقت البيع |
 * | الإعلانات | `ad_daily_spends` بالشيكل بسعر صرف يومه |
 * | العمولات | استحقاقات الفترة الحيّة في دفتر العمولات |
 * | الرواتب ونهاية الخدمة | قيود مسيّر الرواتب (٥٢٠٠ و٥٢١٠) — لا سندات صرف |
 * | المصاريف | سندات الصرف المُرحَّلة (`kind = expense`) بتصنيفاتها |
 *
 * ## خمسة قرارات تجعل الرقم صحيحًا
 *
 * **١. الحساب من البنود لا من إجمالي الطلب.** الإجمالي رقمٌ واحد لا يُقسَّم على
 * الأصناف ولا يُخصَم منه مرتجعٌ جزئيّ، والتكلفة تقابل بنودًا لا طلبات. فحسابُ
 * الإيراد من مكانٍ والتكلفة من آخر يُنتج هامشًا لا يصف شيئًا.
 *
 * **٢. المرتجع الجزئيّ يُخصَم من الطرفين بنسبته.** بند بيع ٤ ورُدّ منه ١ يُحتسب
 * بثلاثة أرباعه بيعًا **وتكلفةً**. وخصمُه من الإيراد وحده يُظهر خسارةً وهمية.
 *
 * **٣. تقسيم الإيراد بأولويّة لا بجمعٍ حرّ.** طلبٌ لمسوّقٍ ومُسنَدٌ لموظف يقع في
 * دلوٍ واحد: مسوّق أولًا، ثم مبيعة مباشرة، ثم موظف، ثم الباقي. فالأقسام الأربعة
 * تجمع إلى الإجمالي بالضبط ولا يُعدّ طلبٌ مرّتين.
 *
 * **٤. لا ازدواج في العمولات.** دفعات العمولات تُسجَّل سنداتِ `payment` لا
 * `expense`، فجمعُ سندات المصروف لا يلتقطها. والمحتسَب هنا **استحقاق الفترة**
 * — وهو تكلفة مبيعاتها سواءٌ صُرف أم لا.
 *
 * **٥. التوصيل خارج القائمة من طرفيه.** رسومُه مالُ شركة التوصيل يمرّ بنا لا
 * إيرادُنا، فإدخاله يُضخّم المبيعات بما لم يُبَع. وإخراجُه من الإيراد وحده كان
 * سيترك تكلفتَه مصروفًا بلا مقابل فيُظهر خسارةً وهمية — فخرج الطرفان معًا:
 * لا رسومَ محصَّلة في الإيراد ولا أجرةَ طرودٍ في المصاريف.
 *
 * ولهذا ثمن يجب أن يُعرَف: **الفرق** بين المُحصَّل والمدفوع — إن حُصِّل ٢٠
 * ودُفع ١٨ — لم يعد يظهر في صافي الدخل. مكانُه تقرير «تكلفة التوصيل».
 *
 * ## حدٌّ يجب أن يُعرَف
 *
 * **الإعلانات ليست في الدفاتر.** `ad_daily_spends` جدولٌ تسويقيّ يُزامَن من
 * ميتا ولا يُنشئ سند صرف، فهو يظهر هنا مصروفًا حقيقيًّا **ولا يظهر في ميزان
 * المراجعة**. أُدرج لأن إغفاله يُضخّم الربح، وأُفرد بسطرٍ ليُعرف مصدره.
 */
class ProfitLossService
{
    /** حصّة البند بعد المرتجع الجزئيّ — الضرب في `1.0` لأن SQLite يقسم الصحيحَين قسمةً صحيحة. */
    private const NET_RATIO = '(CASE WHEN order_items.qty > 0 '
        .'THEN ((order_items.qty - COALESCE(order_items.returned_qty, 0)) * 1.0) / order_items.qty ELSE 0 END)';

    private const REVENUE = '((order_items.qty * order_items.unit_price - order_items.discount) * '.self::NET_RATIO.')';

    private const COST = '((order_items.qty * COALESCE(order_items.wholesale_cost_snapshot, 0)) * '.self::NET_RATIO.')';

    /** @return array<string, mixed> */
    public function report(DateRange $range): array
    {
        [$from, $to] = $range->bounds();

        $revenue = $this->revenueByEarner($from, $to);
        $cogs = $this->cogs($from, $to);

        // الإجمالي هو المبيعات نفسها: لا سطر ثانيًا يُضاف إليها بعد خروج
        // التوصيل. ويبقى المفتاحان معًا كي لا تحتاج الشاشة أن تعرف ذلك.
        $goods = round(array_sum($revenue), 2);
        $grossProfit = round($goods - $cogs, 2);

        $expenses = $this->expenses($from, $to);
        $netIncome = round($grossProfit - $expenses['total'], 2);

        return [
            'revenue' => $revenue + [
                'goods' => $goods,
                'total' => $goods,
            ],
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $goods > 0 ? round($grossProfit / $goods * 100, 1) : null,
            'expenses' => $expenses,
            'net_income' => $netIncome,
            'net_margin' => $goods > 0 ? round($netIncome / $goods * 100, 1) : null,
        ];
    }

    /** بنود الفواتير غير الملغاة في الفترة. */
    private function items(string $from, string $to): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$from, $to]);
    }

    /**
     * الإيراد مقسومًا على البائع — بأولويّة تمنع العدّ المزدوج.
     *
     * @return array<string, float>
     */
    private function revenueByEarner(string $from, string $to): array
    {
        $row = $this->items($from, $to)
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.affiliate_id IS NOT NULL '
                .'THEN '.self::REVENUE.' ELSE 0 END), 0) as affiliates')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.affiliate_id IS NULL AND orders.channel = \'pos\' '
                .'THEN '.self::REVENUE.' ELSE 0 END), 0) as direct')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.affiliate_id IS NULL AND orders.channel != \'pos\' '
                .'AND orders.assigned_to IS NOT NULL THEN '.self::REVENUE.' ELSE 0 END), 0) as staff')
            // الباقي: طلبات المتجر بلا بائعٍ مُسنَد. بلا هذا الدلو لا تجمع
            // الأقسام إلى الإجمالي، ويظهر فرقٌ لا يُفسَّر.
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.affiliate_id IS NULL AND orders.channel != \'pos\' '
                .'AND orders.assigned_to IS NULL THEN '.self::REVENUE.' ELSE 0 END), 0) as store')
            ->first();

        return [
            'staff' => round((float) $row->staff, 2),
            'direct' => round((float) $row->direct, 2),
            'affiliates' => round((float) $row->affiliates, 2),
            'store' => round((float) $row->store, 2),
        ];
    }

    private function cogs(string $from, string $to): float
    {
        return round((float) $this->items($from, $to)->sum(DB::raw(self::COST)), 2);
    }

    /** @return array<string, mixed> */
    private function expenses(string $from, string $to): array
    {
        $ads = round((float) AdDailySpend::query()
            ->whereBetween('spend_date', [substr($from, 0, 10), substr($to, 0, 10)])
            ->sum(DB::raw('amount_usd * fx_rate')), 2);

        // استحقاق الفترة لا المصروف: العمولة تكلفةُ مبيعاتها سواءٌ صُرفت أم لا.
        // والمعكوسة والملغاة خارجه — لا تُستحقّ.
        $commissions = round((float) CommissionEntry::query()
            ->where('entry_type', 'accrual')
            ->whereNotIn('state', ['reversed', 'cancelled'])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount'), 2);

        $payroll = $this->postedExpense(PayrollService::SALARY_EXPENSE_ACCOUNT, $from, $to);
        $endOfService = $this->postedExpense(EndOfServiceService::EXPENSE_ACCOUNT, $from, $to);

        $categories = FinancialVoucher::posted()
            ->where('kind', 'expense')
            ->whereBetween('voucher_date', [substr($from, 0, 10), substr($to, 0, 10)])
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'financial_vouchers.expense_category_id')
            ->groupBy('expense_categories.name')
            ->selectRaw('COALESCE(expense_categories.name, ?) as name, SUM(financial_vouchers.amount) as total', [__('مصاريف غير مصنّفة')])
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => round((float) $r->total, 2)]);

        $vouchers = round((float) $categories->sum('total'), 2);

        return [
            'ads' => $ads,
            'commissions' => $commissions,
            'payroll' => $payroll,
            'end_of_service' => $endOfService,
            'categories' => $categories,
            'vouchers' => $vouchers,
            'total' => round($ads + $commissions + $payroll + $endOfService + $vouchers, 2),
        ];
    }

    /**
     * مصروفٌ مُرحَّل مباشرةً إلى الدفتر — لا عبر سند صرف.
     *
     * الرواتب ومخصّص نهاية الخدمة يُقيَّدان من مسيّر الرواتب لا من سندٍ من نوع
     * `expense`، فجمعُ السندات وحده يُسقطهما — وهما أكبر مصروفٍ في أكثر
     * الشركات. والقراءة من القيد لا من المسيّر: **مدين ناقص دائن**، فالمسيّر
     * المعكوس يُلغي نفسه بلا استثناءٍ في الاستعلام.
     */
    private function postedExpense(string $accountCode, string $from, string $to): float
    {
        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', $accountCode)
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [substr($from, 0, 10), substr($to, 0, 10)])
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }
}
