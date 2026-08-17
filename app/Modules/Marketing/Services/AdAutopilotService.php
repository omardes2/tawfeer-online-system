<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Foundation\Services\Settings;
use App\Modules\Marketing\Models\AdAutopilotDecision;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdExternalMap;
use App\Support\Contracts\AdPlatform\AdPlatformWriterInterface;
use App\Support\Integrations\AdPlatform\AdPlatformManager;
use App\Support\Integrations\AdPlatform\AdSetState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * الطيّار الآلي: يُحوّل أحكام «الميزانية اليومية» إلى أفعالٍ على المنصّة.
 *
 * **لا عقل جديد هنا.** الحكم (أوقف/أنقص/ثبّت/زد) يخرج كما هو من
 * `AdBudgetService` المبنيّ على الربح الحقيقي — سعرُ البيع ناقصَ تكلفة الجملة
 * ناقصَ المرتجع. وهذه هي ميزة النظام على القواعد الآلية في لوحة المنصّة: تلك
 * تعرف «تحويلاتٍ» بتكلفةٍ ما، ولا تعرف أن الصنف ربحه أقلّ من تلك التكلفة.
 * وظيفة هذا الملف أن ينفّذ ذلك الحكم داخل قفصٍ لا يخرج منه.
 *
 * وأربعة قرارات تحكمه:
 *
 * 1. **الفرملة تُؤتمت كاملة، والدوّاسة لا تُؤتمت بعد.** إيقافٌ خاطئ يكلّف ربح
 *    يوم ويُتراجَع عنه بنقرة؛ وزيادةٌ خاطئة تصرف طوال الليل ولا تُستردّ. فالوضع
 *    `brake` ينفّذ `pause` و`decrease` وحدهما — و`increase` غير مطبَّق في أي
 *    وضع، رغم أن الحكم يقولها ويُعرَض للإنسان ليقرّرها بنفسه.
 *
 * 2. **الحالة تُقرأ حيّةً قبل كل قرار.** الميزانية قد تكون غُيّرت من لوحة
 *    المنصّة بعد آخر مزامنة، وتخفيضُ 20% من رقمٍ قديم يُنتج رقمًا لا يقصده أحد.
 *
 * 3. **التهدئة ليست تحفّظًا.** تعديل الميزانية يُعيد المجموعة إلى مرحلة التعلّم
 *    لدى المنصّة؛ فتعديلٌ كل يوم يُبقيها في التعلّم أبدًا — أداءٌ أسوأ ممّا لو
 *    لم يتدخّل أحد. الإيقاف وحده خارج التهدئة: لا تعلّم يُحفَظ في مجموعةٍ خاسرة.
 *
 * 4. **الامتناع يُسجَّل كما يُسجَّل الفعل.** «لم أخفّض لأن الميزانية على مستوى
 *    الحملة» معلومةٌ يحتاجها صاحب العمل؛ وبغيرها يبدو الصمت رضًا.
 */
class AdAutopilotService
{
    public function __construct(
        private readonly AdBudgetService $budget,
        private readonly AdPlatformManager $platform,
    ) {}

    /**
     * إعدادات الطيّار — كلّها من قاعدة البيانات (المبدأ 8).
     *
     * @return array{enabled: bool, mode: string, daily_cap: float, max_decrease_pct: int,
     *               cooldown_days: int, min_budget: float}
     */
    public function settings(): array
    {
        return [
            'enabled' => (bool) Settings::get('ads.autopilot.enabled', false),
            'mode' => (string) Settings::get('ads.autopilot.mode', 'suggest') === 'brake' ? 'brake' : 'suggest',
            'daily_cap' => max(0.0, (float) Settings::get('ads.autopilot.daily_cap', 0)),
            'max_decrease_pct' => min(90, max(1, (int) Settings::get('ads.autopilot.max_decrease_pct', 20))),
            'cooldown_days' => max(0, (int) Settings::get('ads.autopilot.cooldown_days', 3)),
            'min_budget' => max(0.0, (float) Settings::get('ads.autopilot.min_budget', 5)),
        ];
    }

    /**
     * دورة يومٍ كاملة: تخطيطٌ ثم تنفيذ ثم ملخّص.
     *
     * `$reportDay` يوم البيانات لا يوم التشغيل — الطيّار يعمل صباحًا على أرقام
     * أمس، لأن أرقام المنصّة تُراجَع بعد نشرها بيومٍ إلى ثلاثة.
     *
     * @return array<string, mixed>
     */
    public function run(Carbon $reportDay, ?int $userId = null, bool $dryRun = false): array
    {
        $settings = $this->settings();
        $writer = $this->platform->writer();
        $reportDay = $reportDay->copy()->startOfDay();

        $summary = [
            'report_day' => $reportDay,
            'enabled' => $settings['enabled'],
            'mode' => $settings['mode'],
            'writer' => $writer->name(),
            'writer_ready' => $writer->isConfigured(),
            'dry_run' => $dryRun,
            'settings' => $settings,
            'channels' => 0,
            'planned' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0,
            'paused' => 0, 'decreased' => 0,
            'cap_breach' => false,
            'cap_shortfall' => 0.0,
            'budget_total' => 0.0,
            'unknown_budget' => 0,
            'currency' => null,
            'decisions' => collect(),
            'totals' => [],
            'notes' => [],
        ];

        if (! $settings['enabled']) {
            $summary['notes'][] = __('الطيّار مطفأ — لم يُخطَّط شيء ولم يُنفَّذ شيء.');

            return $summary;
        }

        $channels = AdChannel::where('is_active', true)->where('autopilot_enabled', true)->get();
        $summary['channels'] = $channels->count();

        if ($channels->isEmpty()) {
            $summary['notes'][] = __('لم تُسلَّم أي صفحة إلى الطيّار — اختر الصفحات التي يديرها.');

            return $summary;
        }

        $report = $this->budget->report($reportDay);
        $summary['totals'] = $report['totals'];

        $index = $this->adSetsByChannelAndProduct($channels->pluck('id')->all());
        $plan = $this->plan($report, $index, $settings, $writer, $summary);

        // السقف يُطبَّق على الخطة كاملةً لا صفًّا صفًّا: هو حكمٌ على المجموع.
        $plan = $this->enforceCap($plan, $settings, $summary);

        $this->persistAndApply($plan, $reportDay, $settings, $writer, $userId, $dryRun, $summary);

        return $summary;
    }

    /**
     * قرارٌ لكل مجموعة إعلانية تخصّ صفًّا في التقرير.
     *
     * @param  array<string, array<int, AdExternalMap>>  $index
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $summary
     * @return Collection<int, array<string, mixed>>
     */
    private function plan(array $report, array $index, array $settings, AdPlatformWriterInterface $writer, array &$summary): Collection
    {
        $rows = collect($report['rows'])->filter(
            fn (array $row) => ! $row['unassigned'] && isset($index[$row['channel_id'].':'.$row['product_id']]),
        );

        $ids = $rows->flatMap(fn (array $row) => collect($index[$row['channel_id'].':'.$row['product_id']])
            ->pluck('external_id')->all())->unique()->values()->all();

        $states = $this->liveStates($writer, $ids, $summary);
        $cooling = $this->coolingDown($ids, (int) $settings['cooldown_days']);

        return $rows->flatMap(function (array $row) use ($index, $settings, $states, $cooling) {
            return collect($index[$row['channel_id'].':'.$row['product_id']])->map(
                fn (AdExternalMap $map) => $this->decide($row, $map, $states->get($map->external_id), $settings, $cooling),
            );
        })->values();
    }

    /**
     * الحالة الحيّة — وغيابُها ليس فشلًا.
     *
     * محرّك الكتابة قد يكون غير مضبوط بعد (بانتظار صلاحية المنصّة)، وحينها يبقى
     * وضع «الاقتراح» مفيدًا كاملًا: يُكتب القرار وتُعرض أسبابه بلا ميزانيات.
     *
     * @param  array<int, string>  $ids
     * @param  array<string, mixed>  $summary
     * @return Collection<string, AdSetState>
     */
    private function liveStates(AdPlatformWriterInterface $writer, array $ids, array &$summary): Collection
    {
        if ($ids === [] || ! $writer->isConfigured()) {
            if ($ids !== [] && ! $writer->isConfigured()) {
                $summary['notes'][] = __('الكتابة إلى المنصّة غير مفعّلة — القرارات مقترحة ولم تُنفَّذ.');
            }

            return collect();
        }

        try {
            $states = $writer->adSets($ids);
            $summary['currency'] = $states->first()?->currency;

            return $states;
        } catch (Throwable $e) {
            Log::error('ads.autopilot.states_failed', ['message' => $e->getMessage()]);
            $summary['notes'][] = __('تعذّرت قراءة حالة المجموعات من المنصّة: :m', ['m' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * الحكم على مجموعةٍ واحدة.
     *
     * الترتيب مقصود: ما يمنع الفعل يُفحص قبل ما يوجبه — فمجموعةٌ موقوفة أصلًا
     * لا معنى لحساب ميزانيتها الجديدة.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $cooling
     * @return array<string, mixed>
     */
    private function decide(array $row, AdExternalMap $map, ?AdSetState $state, array $settings, array $cooling): array
    {
        $base = [
            'row' => $row,
            'map' => $map,
            'state' => $state,
            'external_id' => $map->external_id,
            'external_name' => $map->external_name,
            'verdict' => $row['verdict']['code'],
            'budget_before' => $state?->dailyBudget,
            'budget_after' => null,
            'currency' => $state?->currency,
        ];

        // `array_merge` لا `+`: العامل `+` **لا يدهس** مفتاحًا موجودًا، فكان
        // `budget_after` يبقى `null` من `$base` ويُرسَل صفرًا إلى المنصّة.
        $decision = fn (array $fields) => array_merge($base, $fields);
        $skip = fn (string $reason) => $decision(['action' => AdAutopilotDecision::ACTION_SKIP, 'reason' => $reason]);

        if ($state && ! $state->isLive()) {
            return $skip(__('المجموعة موقوفة أصلًا على المنصّة — لا شيء ليُفعَل.'));
        }

        $verdict = $row['verdict']['code'];

        if ($verdict === 'stop') {
            return $decision(['action' => AdAutopilotDecision::ACTION_PAUSE, 'reason' => $row['verdict']['reason']]);
        }

        if ($verdict !== 'reduce') {
            // «زد» تُعرَض ولا تُنفَّذ: رفع الميزانية آليًّا مرحلةٌ تالية.
            return $skip($verdict === 'increase'
                ? __('الحكم «زد» — ورفع الميزانية آليًّا غير مفعّل، القرار لك. :r', ['r' => $row['verdict']['reason']])
                : $row['verdict']['reason']);
        }

        if (in_array($map->external_id, $cooling, true)) {
            return $skip(__('تهدئة: عُدِّلت ميزانيتها خلال آخر :n أيام، وتعديلٌ آخر يُعيدها لمرحلة التعلّم.', [
                'n' => (int) $settings['cooldown_days'],
            ]));
        }

        if (! $state) {
            return $skip(__('تعذّرت قراءة ميزانيتها الحالية من المنصّة — لا تخفيض على رقمٍ غير مؤكَّد.'));
        }

        if (! $state->hasOwnDailyBudget()) {
            // تعديلُها يعني تعديل الحملة كلّها ومعها مجموعاتٌ قد تكون رابحة.
            return $skip(__('ميزانيتها مضبوطة على مستوى الحملة لا المجموعة — التخفيض هنا يطال مجموعاتٍ أخرى. خفّضها يدويًّا أو انقل الميزانية إلى مستوى المجموعة.'));
        }

        $after = round($state->dailyBudget * (1 - $settings['max_decrease_pct'] / 100), 2);

        if ($after < $settings['min_budget']) {
            return $decision([
                'action' => AdAutopilotDecision::ACTION_PAUSE,
                'budget_after' => null,
                'reason' => __('التخفيض ينزل بالميزانية تحت الحدّ الأدنى (:m) — الإيقاف أصدق من ميزانيةٍ لا تشتري بيانات. :r', [
                    'm' => $this->money($settings['min_budget'], $state->currency), 'r' => $row['verdict']['reason'],
                ]),
            ]);
        }

        return $decision([
            'action' => AdAutopilotDecision::ACTION_DECREASE,
            'budget_after' => $after,
            'reason' => __('تخفيض :p% من :b إلى :a. :r', [
                'p' => (int) $settings['max_decrease_pct'],
                'b' => $this->money($state->dailyBudget, $state->currency),
                'a' => $this->money($after, $state->currency),
                'r' => $row['verdict']['reason'],
            ]),
        ]);
    }

    /**
     * السقف اليومي — آخر حاجز قبل التنفيذ.
     *
     * يُقاس على المجموع **بعد** الخطة: ما ستتركه الخطة يعمل. وإن تجاوز السقف
     * أُوقفت الأسوأ ربحًا أولًا حتى ينزل تحته — لأن السقف الذي لا يُنفَّذ ليس
     * سقفًا بل رقمًا معروضًا.
     *
     * وسقفٌ غير مضبوط (صفر) لا يمنع الفرملة: الإيقاف والتخفيض لا يزيدان الصرف
     * أبدًا، وتعليقُهما على رقمٍ لا علاقة له بهما يترك خسارةً تجري بلا سبب.
     * يُنبَّه عليه في الملخّص وحسب.
     *
     * @param  Collection<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $summary
     * @return Collection<int, array<string, mixed>>
     */
    private function enforceCap(Collection $plan, array $settings, array &$summary): Collection
    {
        $cap = (float) $settings['daily_cap'];

        // ما ستتركه الخطة يعمل، بميزانيته بعدها.
        $running = $plan->filter(fn (array $d) => $d['action'] !== AdAutopilotDecision::ACTION_PAUSE
            && $d['state'] instanceof AdSetState && $d['state']->isLive());

        $summary['unknown_budget'] = $running->filter(fn (array $d) => ! $d['state']->hasOwnDailyBudget())->count();
        $summary['budget_total'] = round($running->sum(fn (array $d) => (float) ($d['budget_after'] ?? $d['state']->dailyBudget ?? 0)), 2);

        if ($summary['unknown_budget'] > 0) {
            $summary['notes'][] = __(':n مجموعة ميزانيتها على مستوى الحملة، فلا تدخل في احتساب السقف.', [
                'n' => $summary['unknown_budget'],
            ]);
        }

        if ($cap <= 0.0) {
            $summary['notes'][] = __('لم يُضبط السقف اليومي — الفرملة تعمل، والسقف لا يُطبَّق حتى تحدّده.');

            return $plan;
        }

        if ($summary['budget_total'] <= $cap) {
            return $plan;
        }

        $summary['cap_breach'] = true;
        $summary['cap_shortfall'] = round($summary['budget_total'] - $cap, 2);

        // الأسوأ أولًا: أقلّ صافي ربحٍ في النافذة، فالأكبر صرفًا عند التساوي.
        $worstFirst = $running
            ->filter(fn (array $d) => $d['state']->hasOwnDailyBudget())
            ->sortBy([
                fn (array $d) => (float) $d['row']['window']['net_profit'],
                fn (array $d) => -(float) $d['row']['window']['spend'],
            ]);

        $total = $summary['budget_total'];
        $forced = [];

        foreach ($worstFirst as $decision) {
            if ($total <= $cap) {
                break;
            }

            $total = round($total - (float) ($decision['budget_after'] ?? $decision['state']->dailyBudget), 2);
            $forced[$decision['external_id']] = true;
        }

        if ($forced === []) {
            $summary['notes'][] = __('المجموع يتجاوز السقف ولا مجموعةَ يمكن إيقافها — ميزانياتها كلّها على مستوى الحملة.');

            return $plan;
        }

        $summary['budget_total'] = $total;

        return $plan->map(function (array $decision) use ($forced, $cap) {
            if (! isset($forced[$decision['external_id']])) {
                return $decision;
            }

            return array_merge($decision, [
                'action' => AdAutopilotDecision::ACTION_PAUSE,
                'budget_after' => null,
                'reason' => __('إيقافٌ لأن مجموع الميزانيات اليومية يتجاوز السقف (:c) — وهذه الأقلّ ربحًا. :r', [
                    'c' => $this->money($cap, $decision['currency'] ?? ''), 'r' => $decision['reason'],
                ]),
            ]);
        });
    }

    /**
     * الحفظ ثم التنفيذ — بهذا الترتيب.
     *
     * يُكتب القرار قبل إرساله: نداءٌ نجح ثم انقطع الاتصال يترك المنصّة مُعدَّلة
     * وسجلَّنا فارغًا، وهو أسوأ الحالتين. والحفظ أولًا يجعل الأثر موجودًا دائمًا
     * ولو بحالة «فشل».
     *
     * @param  Collection<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $summary
     */
    private function persistAndApply(
        Collection $plan,
        Carbon $reportDay,
        array $settings,
        AdPlatformWriterInterface $writer,
        ?int $userId,
        bool $dryRun,
        array &$summary,
    ): void {
        $live = $settings['mode'] === 'brake' && $writer->isConfigured() && ! $dryRun;
        $decisions = collect();

        foreach ($plan as $item) {
            $record = $this->record($item, $reportDay, $settings, $userId);

            // قرارٌ نُفِّذ اليوم بالفعل: لا يُعاد تنفيذه ولا يُدهَس سجلُّه.
            if ($record === null) {
                $summary['skipped']++;

                continue;
            }

            $summary['planned']++;

            if ($item['action'] === AdAutopilotDecision::ACTION_SKIP) {
                $record->update(['status' => AdAutopilotDecision::STATUS_SKIPPED]);
                $summary['skipped']++;
                $decisions->push($record);

                continue;
            }

            if (! $live) {
                $decisions->push($record); // يبقى «مقترحًا» بانتظار قرار إنسان.

                continue;
            }

            $this->apply($record, $writer, $item, $summary);
            $decisions->push($record);
        }

        $summary['decisions'] = $decisions;
    }

    /** @param  array<string, mixed>  $summary */
    private function apply(AdAutopilotDecision $record, AdPlatformWriterInterface $writer, array $item, array &$summary): void
    {
        try {
            match ($item['action']) {
                AdAutopilotDecision::ACTION_PAUSE => $writer->pause($record->external_id),
                AdAutopilotDecision::ACTION_DECREASE,
                AdAutopilotDecision::ACTION_INCREASE => $writer->setDailyBudget($record->external_id, (float) $item['budget_after']),
                AdAutopilotDecision::ACTION_RESUME => $writer->resume($record->external_id),
                default => null,
            };

            $record->update(['status' => AdAutopilotDecision::STATUS_APPLIED, 'applied_at' => now()]);
            $summary['applied']++;
            $summary[$item['action'] === AdAutopilotDecision::ACTION_PAUSE ? 'paused' : 'decreased']++;
        } catch (Throwable $e) {
            // مجموعةٌ واحدة تفشل لا توقف الدورة: الباقي أحقّ بأن يُنفَّذ.
            Log::error('ads.autopilot.apply_failed', [
                'adset' => $record->external_id, 'action' => $record->action, 'message' => $e->getMessage(),
            ]);

            $record->update(['status' => AdAutopilotDecision::STATUS_FAILED, 'error' => $e->getMessage()]);
            $summary['failed']++;
        }
    }

    /**
     * كتابة صفّ القرار — ويعيد `null` إن كان قرارُ اليوم قد نُفِّذ فعلًا.
     *
     * الإعادة تحدث كثيرًا عمليًّا: يشغّل المستخدم الطيّار من الشاشة بعد أن عملت
     * الجدولة. فما نُفِّذ لا يُعاد تنفيذه، و**سجلُّه لا يُدهَس** — لو استُبدل
     * «أوقفتُ هذه ولماذا» بـ«كانت موقوفة أصلًا» لضاع أثر الفعل نفسه.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $settings
     */
    private function record(array $item, Carbon $reportDay, array $settings, ?int $userId): ?AdAutopilotDecision
    {
        $row = $item['row'];
        $window = $row['window'];

        /*
        | البحث بـ`whereDate` لا بمساواة نصّية: العمود من نوع تاريخ لكن Laravel
        | يكتب فيه بصيغة الوقت الكاملة، فمقارنتُه بـ'2026-08-17' لا تجد الصفّ
        | فيصطدم `updateOrCreate` بالفهرس الفريد بدل أن يُحدِّث.
        */
        $existing = AdAutopilotDecision::query()
            ->whereDate('decided_on', Carbon::today())
            ->where('external_id', $item['external_id'])
            ->where('source', 'auto')
            ->first();

        if ($existing?->status === AdAutopilotDecision::STATUS_APPLIED) {
            return null;
        }

        $values = [
            'report_day' => $reportDay->toDateString(),
            'ad_channel_id' => $row['channel_id'] ?: null,
            'product_id' => $row['product_id'] ?: null,
            'external_name' => $item['external_name'],
            'action' => $item['action'],
            'verdict' => $item['verdict'],
            'reason' => $item['reason'],
            'budget_before' => $item['budget_before'],
            'budget_after' => $item['budget_after'],
            'currency' => $item['currency'],
            'window_spend' => $window['spend'],
            'window_orders' => $window['orders'],
            'window_cpa' => $window['orders'] > 0 ? $window['cpa'] : null,
            'window_net_profit' => $window['net_profit'],
            'status' => AdAutopilotDecision::STATUS_PLANNED,
            'error' => null,
            'mode' => $settings['mode'],
            'applied_at' => null,
            'created_by' => $userId,
        ];

        if ($existing) {
            $existing->update($values);

            return $existing;
        }

        return AdAutopilotDecision::create($values + [
            'decided_on' => Carbon::today(),
            'external_id' => $item['external_id'],
            'source' => 'auto',
        ]);
    }

    /**
     * إيقافٌ فوري لكل مجموعة إعلانية مربوطة — الزرّ الأحمر.
     *
     * لا يقتصر على الصفحات المُسلَّمة للطيّار: من يضغط «أوقف كل الإعلانات» يقصد
     * كلَّها. وهو فعلُ إنسانٍ صريح لا قرارُ خوارزمية، ويُسجَّل كذلك.
     *
     * @return array{stopped: int, failed: int, errors: array<int, string>}
     */
    public function stopAll(?int $userId): array
    {
        $writer = $this->platform->writer();
        $result = ['stopped' => 0, 'failed' => 0, 'errors' => []];

        if (! $writer->isConfigured()) {
            $result['errors'][] = __('الكتابة إلى المنصّة غير مفعّلة — لا يمكن الإيقاف من هنا.');

            return $result;
        }

        $ids = $this->linkedAdSetIds();

        if ($ids === []) {
            return $result;
        }

        $states = $writer->adSets($ids);

        foreach ($ids as $id) {
            $state = $states->get($id);

            if ($state && ! $state->isLive()) {
                continue; // موقوفة أصلًا.
            }

            try {
                $writer->pause($id);
                $result['stopped']++;

                // `whereDate` للسبب نفسه المشروح في `record()`.
                $row = AdAutopilotDecision::query()
                    ->whereDate('decided_on', Carbon::today())
                    ->where('external_id', $id)->where('source', 'manual')->first();

                $values = [
                    'report_day' => Carbon::yesterday()->toDateString(),
                    'external_name' => $state?->name,
                    'action' => AdAutopilotDecision::ACTION_PAUSE,
                    'reason' => __('إيقاف طارئ لكل الإعلانات بطلبٍ من المستخدم.'),
                    'budget_before' => $state?->dailyBudget,
                    'currency' => $state?->currency,
                    'status' => AdAutopilotDecision::STATUS_APPLIED,
                    'applied_at' => now(),
                    'mode' => 'brake',
                    'created_by' => $userId,
                ];

                $row
                    ? $row->update($values)
                    : AdAutopilotDecision::create($values + [
                        'decided_on' => Carbon::today(),
                        'external_id' => $id,
                        'source' => 'manual',
                    ]);
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $id.': '.$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * التراجع عن قرارٍ نُفِّذ — بعكس فعله، لا بإعادة حسابه.
     *
     * إعادة الحساب تعطي رقمًا آخر إن تغيّرت الإعدادات بينهما؛ والتراجع يجب أن
     * يعيد ما كان بالضبط.
     */
    public function revert(AdAutopilotDecision $decision, int $userId): void
    {
        if (! $decision->isRevertible()) {
            throw new \RuntimeException(__('لا يُتراجَع عن هذا القرار.'));
        }

        $writer = $this->platform->writer();

        if (! $writer->isConfigured()) {
            throw new \RuntimeException(__('الكتابة إلى المنصّة غير مفعّلة.'));
        }

        match ($decision->action) {
            AdAutopilotDecision::ACTION_PAUSE => $writer->resume($decision->external_id),
            AdAutopilotDecision::ACTION_RESUME => $writer->pause($decision->external_id),
            default => $writer->setDailyBudget($decision->external_id, (float) $decision->budget_before),
        };

        $decision->update([
            'status' => AdAutopilotDecision::STATUS_REVERTED,
            'reverted_at' => now(),
            'reverted_by' => $userId,
        ]);
    }

    /**
     * المجموعات الإعلانية مفهرسةً بـ«قناة:صنف».
     *
     * الطريق: المجموعة ← حملتها الأمّ ← الصفحة المربوطة بتلك الحملة. ومجموعةٌ
     * حملتُها غير مربوطة تسقط — لا تُخمَّن صفحتُها.
     *
     * @param  array<int, int>  $channelIds
     * @return array<string, array<int, AdExternalMap>>
     */
    private function adSetsByChannelAndProduct(array $channelIds): array
    {
        $campaigns = AdExternalMap::query()
            ->where('external_type', AdExternalMap::TYPE_CAMPAIGN)
            ->whereIn('ad_channel_id', $channelIds)
            ->pluck('ad_channel_id', 'external_id');

        if ($campaigns->isEmpty()) {
            return [];
        }

        $index = [];

        AdExternalMap::query()
            ->where('external_type', AdExternalMap::TYPE_ADSET)
            ->where('is_ignored', false)
            ->whereNotNull('product_id')
            ->whereIn('parent_external_id', $campaigns->keys())
            ->get()
            ->each(function (AdExternalMap $map) use ($campaigns, &$index) {
                $index[$campaigns[$map->parent_external_id].':'.$map->product_id][] = $map;
            });

        return $index;
    }

    /** @return array<int, string> */
    private function linkedAdSetIds(): array
    {
        return AdExternalMap::query()
            ->where('external_type', AdExternalMap::TYPE_ADSET)
            ->where('is_ignored', false)
            ->whereNotNull('product_id')
            ->pluck('external_id')
            ->all();
    }

    /**
     * المجموعات التي عُدِّلت ميزانيتها حديثًا فهي في تهدئة.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function coolingDown(array $ids, int $days): array
    {
        if ($days <= 0 || $ids === []) {
            return [];
        }

        return AdAutopilotDecision::query()
            ->applied()
            ->whereIn('external_id', $ids)
            ->whereIn('action', [AdAutopilotDecision::ACTION_DECREASE, AdAutopilotDecision::ACTION_INCREASE])
            ->whereDate('decided_on', '>', Carbon::today()->subDays($days))
            ->pluck('external_id')
            ->unique()
            ->all();
    }

    private function money(float $amount, string $currency): string
    {
        return trim(number_format($amount, 2).' '.$currency);
    }
}
