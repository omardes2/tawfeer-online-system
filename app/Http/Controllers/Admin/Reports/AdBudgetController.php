<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreAdSpendRequest;
use App\Http\Requests\Reports\StoreAdThresholdsRequest;
use App\Http\Requests\Reports\StoreOperatingCostRequest;
use App\Http\Requests\Reports\StoreSharedAdSpendRequest;
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
            // الإعلانات المشتركة بحكمٍ على مستواها — وهو الحكم القابل للتنفيذ.
            'sharedAds' => $this->budget->sharedAds($day, $channelId),
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

    /**
     * حفظ إعلانٍ بميزانيةٍ واحدة لعدّة أصناف.
     *
     * يُخزَّن صفًّا واحدًا بلا صنف، وأصنافُه في جدول الربط؛ ويُوزَّع على الأصناف
     * عند القراءة بحصّة مبيعات كلٍّ منها. ولا يُجمَع مع صفّ صنفٍ مفرد في المفتاح
     * الفريد، فيتعدّد الإعلان المشترك في اليوم الواحد وهو المطلوب.
     */
    public function storeSharedSpend(StoreSharedAdSpendRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $spend = AdDailySpend::create([
            'spend_date' => Carbon::parse($data['spend_date'])->startOfDay(),
            'ad_channel_id' => $data['ad_channel_id'],
            'product_id' => null,
            'label' => $data['label'],
            'amount_usd' => $data['amount_usd'],
            'fx_rate' => $data['fx_rate'],
            'conversations' => $data['conversations'],
            'entered_by' => $request->user()->id,
        ]);

        $spend->products()->sync($data['product_ids']);

        return back()->with('success', __('حُفظ الإعلان المشترك — ويُوزَّع على أصنافه بحصّة مبيعاتها.'));
    }

    /** حذف صفّ صرف أُدخل خطأً — الصفر ليس كالغياب في مؤشّر الأيام الناقصة. */
    public function destroySpend(Request $request, AdDailySpend $spend): RedirectResponse
    {
        abort_unless($request->user()->can('reports.ad_budget.manage'), 403);

        $spend->delete();

        return back()->with('success', __('حُذف صفّ الصرف.'));
    }

    /**
     * إقرارٌ بأن صنفًا لم يُعلَن عليه في النافذة.
     *
     * الفرق بين «صفر» و«لا شيء» هو الفرق بين حكمٍ وصمت: الصفّ الغائب يعني «لم
     * يُنسخ الصرف بعد» فتُحجب النتيجة، والصفر المُدخَل يعني «لا إعلان على هذا
     * الصنف» فيظهر ربحه العضويّ كما هو. وبلا هذا الزرّ يبقى الصنف الذي لا
     * يُعلَن عليه أبدًا معلَّقًا بشارة «بانتظار الإدخال» بلا شيء يُنتظر.
     *
     * تُملأ **الفجوات وحدها**: يومٌ له صرفٌ مُدخَل لا يُدهَس بصفر.
     */
    public function markNoAds(Request $request, int $channel, int $product, string $day): RedirectResponse
    {
        abort_unless($request->user()->can('reports.ad_budget.manage'), 403);

        abort_unless(AdChannel::whereKey($channel)->exists() && Product::whereKey($product)->exists(), 404);

        $to = Carbon::parse($day)->startOfDay();
        $windowDays = (int) $this->budget->thresholds()['window_days'];
        $rate = (float) Settings::get('ads.usd_rate', 3.7);

        $filled = 0;
        for ($d = $to->copy()->subDays($windowDays - 1); $d->lte($to); $d->addDay()) {
            $created = AdDailySpend::firstOrCreate(
                ['spend_date' => $d->copy(), 'ad_channel_id' => $channel, 'product_id' => $product],
                ['amount_usd' => 0, 'fx_rate' => $rate, 'conversations' => 0, 'entered_by' => $request->user()->id],
            );

            $filled += $created->wasRecentlyCreated ? 1 : 0;
        }

        return back()->with('success', __('سُجّل «لا إعلان» على :n من أيام النافذة.', ['n' => $filled]));
    }

    /**
     * ضبط عتبات الحكم ونافذته.
     *
     * كانت الخمسة محبوسة في جدول الإعدادات بلا شاشة، فتُغيَّر باستعلام SQL أو لا
     * تُغيَّر — وهي أرقام عملٍ تتبع حجم المتجر وهامشه: متجرٌ يوزّع ميزانيته على
     * أصنافٍ كثيرة لا يبلغ فيها الصنف عشرة طلبات في ثلاثة أيام، فتصمت اللوحة عن
     * أكثر صفوفه ويبقى صاحبها بلا قرار.
     */
    public function storeThresholds(StoreAdThresholdsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Settings::set('ads.window_days', (int) $data['window_days'], 'ads', 'integer');
        Settings::set('ads.min_orders', (int) $data['min_orders'], 'ads', 'integer');

        foreach (['cpa_increase_below', 'cpa_hold_below', 'cpa_reduce_below'] as $key) {
            Settings::set('ads.'.$key, round((float) $data[$key], 2), 'ads', 'double');
        }

        return back()->with('success', __('حُدّثت عتبات الحكم — وتسري على الحساب فورًا.'));
    }

    /**
     * ضبط سعر صرف الدولار الافتراضي.
     *
     * كان الرقم في الإعدادات بلا شاشةٍ تعدّله، فيبقى ما ضُبط أوّل مرّة (3.7)
     * ويُحتسب به كل صرفٍ جديد مهما تغيّر السوق — وهو رقمٌ يتحرّك أسبوعيًّا،
     * وخطؤه يُضخّم تكلفة الطلب بالشيكل فيُوقَف إعلانٌ رابح.
     *
     * والصفوف المحفوظة لا تتغيّر تلقائيًّا: كل صفٍّ يحمل سعر يومه وربحُ ذلك
     * اليوم مثبَّت عليه. أمّا تصحيح يومٍ أُدخل بسعرٍ خاطئ فطلبٌ صريح.
     */
    public function storeRate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('reports.ad_budget.manage'), 403);

        $data = $request->validate([
            'usd_rate' => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'apply_day' => ['nullable', 'date'],
        ]);

        $rate = round((float) $data['usd_rate'], 4);
        Settings::set('ads.usd_rate', $rate, 'ads', 'double');

        if (empty($data['apply_day'])) {
            return back()->with('success', __('حُدِّث سعر الصرف الافتراضي إلى :r — ويسري على ما يُدخَل بعده.', ['r' => $rate]));
        }

        $day = Carbon::parse($data['apply_day']);

        $updated = AdDailySpend::query()
            ->whereBetween('spend_date', [
                $day->copy()->startOfDay()->format('Y-m-d H:i:s'),
                $day->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->update(['fx_rate' => $rate]);

        return back()->with('success', __('حُدِّث سعر الصرف إلى :r، وأُعيد احتساب :n صفًّا في يوم :d.', [
            'r' => $rate, 'n' => $updated, 'd' => $day->toDateString(),
        ]));
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
