<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\AutopilotSettingsRequest;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Marketing\Models\AdAutopilotDecision;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Services\AdAutopilotService;
use App\Modules\Marketing\Services\AdBudgetService;
use App\Support\Integrations\AdPlatform\AdPlatformManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * شاشة الطيّار الآلي: ما قرّره، ولماذا، وبأي أرقام — وأدوات إيقافه.
 *
 * الشاشة ليست ترفًا فوق الأتمتة بل شرطُها: نظامٌ يتصرّف في المال وحده بلا
 * واجهةٍ تعرض قراراته وتُلغيها صندوقٌ أسود، ولا يجوز رفع صلاحياته قبل أن
 * يُقرأ سجلُّه أسابيع.
 */
class AdAutopilotController extends Controller
{
    public function __construct(
        private readonly AdAutopilotService $autopilot,
        private readonly AdBudgetService $budget,
        private readonly AdPlatformManager $platform,
    ) {}

    public function index(Request $request): View
    {
        $day = $this->day($request);
        $writer = $this->platform->writer();

        $decisions = AdAutopilotDecision::with(['channel', 'product', 'revertedBy'])
            ->whereDate('report_day', $day)
            ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'applied' THEN 1 WHEN 'planned' THEN 2 ELSE 3 END")
            ->orderByDesc('window_spend')
            ->get();

        $report = $this->budget->report($day);

        return view('admin.marketing.autopilot', [
            'day' => $day,
            'settings' => $this->autopilot->settings(),
            'channels' => AdChannel::ordered()->get(),
            'decisions' => $decisions,
            'stats' => $this->stats($decisions),
            'totals' => $report['totals'],
            'thresholds' => $report['thresholds'],
            'writerReady' => $writer->isConfigured(),
            'writerDriver' => $writer->name(),
            'currency' => (string) Settings::get('store.currency_symbol', '₪'),
        ]);
    }

    public function updateSettings(AutopilotSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Settings::set('ads.autopilot.enabled', (bool) ($data['enabled'] ?? false), 'ads', 'boolean');
        Settings::set('ads.autopilot.mode', $data['mode'], 'ads', 'string');
        Settings::set('ads.autopilot.daily_cap', (float) $data['daily_cap'], 'ads', 'double');
        Settings::set('ads.autopilot.max_decrease_pct', (int) $data['max_decrease_pct'], 'ads', 'integer');
        Settings::set('ads.autopilot.cooldown_days', (int) $data['cooldown_days'], 'ads', 'integer');
        Settings::set('ads.autopilot.min_budget', (float) $data['min_budget'], 'ads', 'double');

        // الصفحات المُسلَّمة للطيّار — ما لم يُختَر يُسحَب منه.
        $chosen = array_map('intval', $data['channels'] ?? []);
        AdChannel::query()->update(['autopilot_enabled' => false]);

        if ($chosen !== []) {
            AdChannel::whereIn('id', $chosen)->update(['autopilot_enabled' => true]);
        }

        return back()->with('success', __('حُفظت إعدادات الطيّار.'));
    }

    /** تشغيلٌ فوري بدل انتظار الدورة الصباحية — للتجربة بعد تغيير الإعدادات. */
    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.autopilot.manage'), 403);

        $dryRun = $request->boolean('dry_run');

        try {
            $summary = $this->autopilot->run($this->day($request), $request->user()->id, $dryRun);
        } catch (Throwable $e) {
            return back()->with('error', __('فشل الطيّار: :m', ['m' => $e->getMessage()]));
        }

        if (! $summary['enabled']) {
            return back()->with('error', __('الطيّار مطفأ — فعّله أولًا.'));
        }

        return back()->with('success', __('مخطَّط :p · منفَّذ :a · موقوف :s · مخفَّض :d · متخطّى :k · فاشل :f', [
            'p' => $summary['planned'], 'a' => $summary['applied'], 's' => $summary['paused'],
            'd' => $summary['decreased'], 'k' => $summary['skipped'], 'f' => $summary['failed'],
        ]));
    }

    /** التراجع عن قرارٍ نُفِّذ — بعكس فعله بالضبط. */
    public function revert(Request $request, AdAutopilotDecision $decision): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.autopilot.manage'), 403);

        try {
            $this->autopilot->revert($decision, $request->user()->id);
        } catch (Throwable $e) {
            return back()->with('error', __('تعذّر التراجع: :m', ['m' => $e->getMessage()]));
        }

        return back()->with('success', __('أُلغي القرار وأُعيدت الحالة كما كانت.'));
    }

    /** الزرّ الأحمر: إيقاف كل مجموعة إعلانية مربوطة فورًا. */
    public function stopAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.autopilot.manage'), 403);

        $result = $this->autopilot->stopAll($request->user()->id);

        if ($result['errors'] !== [] && $result['stopped'] === 0) {
            return back()->with('error', implode(' · ', array_slice($result['errors'], 0, 3)));
        }

        return back()->with('success', __('أُوقفت :n مجموعة إعلانية:f', [
            'n' => $result['stopped'],
            'f' => $result['failed'] > 0 ? __('، وتعذّر إيقاف :x', ['x' => $result['failed']]) : '',
        ]));
    }

    /**
     * ملخّص قرارات اليوم.
     *
     * @param  Collection<int, AdAutopilotDecision>  $decisions
     * @return array<string, int|float>
     */
    private function stats($decisions): array
    {
        $effective = $decisions->whereIn('action', AdAutopilotDecision::EFFECTIVE_ACTIONS);

        return [
            'total' => $decisions->count(),
            'applied' => $decisions->where('status', AdAutopilotDecision::STATUS_APPLIED)->count(),
            'planned' => $decisions->where('status', AdAutopilotDecision::STATUS_PLANNED)->count(),
            'failed' => $decisions->where('status', AdAutopilotDecision::STATUS_FAILED)->count(),
            'reverted' => $decisions->where('status', AdAutopilotDecision::STATUS_REVERTED)->count(),
            'paused' => $effective->where('action', AdAutopilotDecision::ACTION_PAUSE)->count(),
            'decreased' => $effective->where('action', AdAutopilotDecision::ACTION_DECREASE)->count(),
            // ما وفّره التخفيض يوميًّا — الفرق بين ما كان وما صار.
            'saved' => round($effective
                ->where('status', AdAutopilotDecision::STATUS_APPLIED)
                ->sum(fn (AdAutopilotDecision $d) => (float) $d->budget_before - (float) ($d->budget_after ?? 0)), 2),
        ];
    }

    /** يوم البيانات المعروض — وافتراضًا أمس، كصفحة الميزانية اليومية. */
    private function day(Request $request): Carbon
    {
        try {
            return $request->input('day')
                ? Carbon::parse($request->input('day'))->startOfDay()
                : Carbon::yesterday();
        } catch (Throwable) {
            return Carbon::yesterday();
        }
    }
}
