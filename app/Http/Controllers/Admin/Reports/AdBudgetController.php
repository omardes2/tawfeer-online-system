<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreAdSpendRequest;
use App\Http\Requests\Reports\StoreOperatingCostRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Marketing\Models\OperatingDailyCost;
use App\Modules\Marketing\Services\AdBudgetService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «الميزانية اليومية» — ربح كل صنف على كل صفحة مقابل ما صُرف عليه إعلانيًّا.
 *
 * الصفحة ليوم واحد لا لمدًى: الإدخال يومي، والحكم يُحسب على نافذةٍ تنتهي باليوم
 * المعروض. واليوم الافتراضي **أمس** لا اليوم، لأن أرقام Meta تُنسخ في اليوم
 * التالي — فصفحة اليوم الجاري ستكون بلا صرفٍ مُدخَل، وكل صنف فيها يبدو رابحًا.
 */
class AdBudgetController extends Controller
{
    public function __construct(private readonly AdBudgetService $budget) {}

    public function index(Request $request): View|StreamedResponse
    {
        $day = $this->day($request);
        $channelId = (int) $request->query('channel') ?: null;

        $report = $this->budget->report($day, $channelId);

        if ($request->query('export') === 'csv') {
            return $this->csv($report);
        }

        return view('admin.reports.ad_budget', $report + [
            'channelId' => $channelId,
            'allChannels' => AdChannel::ordered()->get(),
            // الاسم والرمز معًا: قائمة الأصناف طويلة، والبحث يقبل الاثنين.
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'currency' => (string) Settings::get('store.currency_symbol', '₪'),
            'company' => (string) Settings::get('store.name', 'توفير أونلاين'),
            'unlinkedChannels' => AdChannel::where('is_active', true)->whereNull('delivery_business_id')->count(),
        ]);
    }

    /** حفظ صرف صنفٍ في قناةٍ ليوم — يُحدِّث الصفّ القائم ولا يُراكم عليه. */
    public function storeSpend(StoreAdSpendRequest $request): RedirectResponse
    {
        $data = $request->validated();

        AdDailySpend::updateOrCreate(
            [
                // تُوحَّد الصيغة عند الكتابة والبحث معًا، وإلّا لم يجد `updateOrCreate`
                // الصفَّ القائم فاصطدم بالفهرس الفريد بدل أن يُحدِّثه.
                'spend_date' => Carbon::parse($data['spend_date'])->startOfDay(),
                'ad_channel_id' => $data['ad_channel_id'],
                'product_id' => $data['product_id'],
            ],
            [
                'amount_usd' => $data['amount_usd'],
                'fx_rate' => $data['fx_rate'],
                'conversations' => $data['conversations'],
                'entered_by' => $request->user()->id,
            ],
        );

        return back()->with('success', __('حُفظ صرف الإعلان.'));
    }

    /** حذف صفّ صرف أُدخل خطأً — الصفر ليس كالغياب في مؤشّر الأيام الناقصة. */
    public function destroySpend(Request $request, AdDailySpend $spend): RedirectResponse
    {
        abort_unless($request->user()->can('reports.ad_budget.manage'), 403);

        $spend->delete();

        return back()->with('success', __('حُذف صفّ الصرف.'));
    }

    /** ضبط المصروف التشغيلي الثابت — بتاريخ سريان فلا يُعاد كتابة الماضي. */
    public function storeFixedCost(StoreOperatingCostRequest $request): RedirectResponse
    {
        $data = $request->validated();

        OperatingDailyCost::updateOrCreate(
            ['effective_from' => $data['effective_from']],
            [
                'amount' => $data['amount'],
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', __('حُدِّث المصروف التشغيلي اليومي، ويسري من التاريخ المحدَّد فصاعدًا.'));
    }

    /** اليوم المعروض — من الطلب، وافتراضًا أمس. */
    private function day(Request $request): Carbon
    {
        $raw = $request->query('day');

        try {
            return $raw ? Carbon::parse($raw)->startOfDay() : Carbon::yesterday();
        } catch (\Throwable) {
            return Carbon::yesterday();
        }
    }

    private function csv(array $report): StreamedResponse
    {
        $head = [
            __('القناة'), __('الصنف'), __('الطلبات'), __('المبيعات'), __('الربح قبل الإعلان'),
            __('الصرف $'), __('الصرف'), __('المحادثات'), __('صافي الربح'), __('تكلفة الطلب'), __('التقييم'),
        ];

        $rows = $report['rows']->map(fn ($r) => [
            $r['channel'], $r['product'], $r['orders'],
            number_format($r['sales'], 2, '.', ''),
            number_format($r['profit_before_ads'], 2, '.', ''),
            number_format($r['spend_usd'], 2, '.', ''),
            number_format($r['spend'], 2, '.', ''),
            $r['conversations'],
            number_format($r['net_profit'], 2, '.', ''),
            $r['cpa'] === null ? '' : number_format($r['cpa'], 2, '.', ''),
            $r['verdict']['label'],
        ]);

        $name = 'daily-budget-'.$report['day']->toDateString();

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لعرض العربية في Excel.
            fputcsv($out, $head);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
