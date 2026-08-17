@use('App\Modules\Marketing\Models\AdAutopilotDecision')

<x-app-layout :title="__('الطيّار الآلي للإعلانات')">
    @php
        $can = auth()->user()->can('marketing.autopilot.manage');
        $dayString = $day->toDateString();
        $query = fn (array $extra = []) => request()->fullUrlWithQuery($extra);
        $brake = $settings['mode'] === 'brake';

        // عملة الحساب الإعلاني تُقرأ من القرارات نفسها لا من إعدادات المتجر:
        // الميزانية تُكتب بعملة المنصّة، وعرضُها بالشيكل يجعل السقف يبدو رقمًا آخر.
        $adCurrency = $decisions->firstWhere('currency', '!=', null)?->currency ?? '';
        $budget = fn ($amount) => $amount === null ? '—' : trim(number_format((float) $amount, 2).' '.$adCurrency);

        $statusTones = [
            AdAutopilotDecision::STATUS_APPLIED => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            AdAutopilotDecision::STATUS_PLANNED => 'bg-sky-50 text-sky-700 ring-sky-200',
            AdAutopilotDecision::STATUS_FAILED => 'bg-rose-50 text-rose-700 ring-rose-200',
            AdAutopilotDecision::STATUS_REVERTED => 'bg-amber-50 text-amber-800 ring-amber-200',
            AdAutopilotDecision::STATUS_SKIPPED => 'bg-gray-100 text-gray-500 ring-gray-200',
        ];
        $actionTones = [
            AdAutopilotDecision::ACTION_PAUSE => 'text-rose-700',
            AdAutopilotDecision::ACTION_DECREASE => 'text-amber-700',
            AdAutopilotDecision::ACTION_RESUME => 'text-emerald-700',
            AdAutopilotDecision::ACTION_INCREASE => 'text-emerald-700',
        ];
    @endphp

    <x-admin.header
        :title="__('الطيّار الآلي للإعلانات')"
        :description="__('يقرأ حكم «الميزانية اليومية» على كل صنف في كل صفحة، ويوقف الخاسر ويخفّض ما دون العتبة — داخل سقفٍ تحدّده أنت.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التسويق') => null, __('الطيّار الآلي') => null]" />

    {{--
        ثلاثة أقفال بين النظام وبين إنفاق المال، ويُقال أيّها مغلق الآن صراحةً.
        صمتُ الطيّار له أسباب مختلفة تمامًا، وخلطُها يجعل صاحب العمل يظنّ الأتمتة
        تعمل وهي لم تبدأ.
    --}}
    @php
        $locks = collect([
            ! $settings['enabled'] ? __('الطيّار مطفأ.') : null,
            $settings['enabled'] && ! $brake ? __('الوضع «اقتراح»: يكتب القرارات ولا ينفّذها.') : null,
            ! $writerReady ? __('الكتابة إلى المنصّة غير مفعّلة — لا رمز كتابة على الخادم.') : null,
            $channels->where('autopilot_enabled', true)->isEmpty() ? __('لم تُسلَّم أي صفحة إلى الطيّار.') : null,
        ])->filter();
    @endphp

    @if ($locks->isNotEmpty())
        <div class="mb-5">
            <x-admin.alert tone="amber" :title="__('الطيّار لا ينفّذ الآن')">
                <ul class="list-disc ps-5 space-y-0.5">
                    @foreach ($locks as $lock)<li>{{ $lock }}</li>@endforeach
                </ul>
                @unless ($writerReady)
                    <span class="block mt-2 text-xs">
                        {{ __('الكتابة لها رمزٌ وحسابٌ إعلاني مستقلّان عن اللذين تُقرأ منهما حملات الرسائل — لا يرثان شيئًا منهما. صلاحية الرمز «ads_management» وتحتاج مراجعة تطبيق من المنصّة. يُضبطان في ملف البيئة على الخادم:') }}
                        <code class="block mt-1 text-[11px] font-mono bg-amber-100 rounded p-2 leading-relaxed" dir="ltr">ADS_WRITE_DRIVER=meta<br>META_ADS_WRITE_TOKEN=…<br>META_ADS_WRITE_ACCOUNT_ID=…</code>
                    </span>
                @endunless
            </x-admin.alert>
        </div>
    @elseif ($brake)
        <div class="mb-5">
            <x-admin.alert tone="green" :title="__('الطيّار يعمل')">
                {{ __('يوقف الخاسر ويخفّض ما دون العتبة وحده كل صباح. ولا يرفع ميزانيةً ولا يُنشئ حملة في أي وضع — هذا قرارك أنت.') }}
            </x-admin.alert>
        </div>
    @endif

    {{-- شريط اليوم — الافتراضي أمس، كصفحة الميزانية اليومية تمامًا. --}}
    <div class="admin-card p-4 mb-5 flex flex-wrap items-end gap-3">
        <form method="GET" action="{{ route('admin.marketing.autopilot.index') }}" class="flex items-end gap-1">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('يوم البيانات') }}</label>
                <div class="flex items-center gap-1">
                    <a href="{{ $query(['day' => $day->copy()->subDay()->toDateString()]) }}" class="btn-secondary btn-sm">‹</a>
                    <input type="date" name="day" value="{{ $dayString }}" max="{{ now()->toDateString() }}"
                           onchange="this.form.submit()"
                           class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <a href="{{ $query(['day' => $day->copy()->addDay()->toDateString()]) }}" class="btn-secondary btn-sm">›</a>
                </div>
            </div>
        </form>

        @if ($can)
            <form method="POST" action="{{ route('admin.marketing.autopilot.run') }}" class="flex items-end gap-2 ms-auto">
                @csrf
                <input type="hidden" name="day" value="{{ $dayString }}">
                <button type="submit" class="btn-primary btn-sm">{{ __('شغّل الآن') }}</button>
                <button type="submit" name="dry_run" value="1" class="btn-secondary btn-sm">{{ __('تجربة بلا تنفيذ') }}</button>
            </form>

            {{-- الزرّ الأحمر: يوقف كل مجموعة مربوطة، لا الصفحات المُسلَّمة وحدها. --}}
            <x-admin.confirm
                :action="route('admin.marketing.autopilot.stop_all')"
                method="POST"
                :trigger="__('أوقف كل الإعلانات')"
                :title="__('إيقاف كل الإعلانات')"
                :confirm="__('نعم، أوقفها الآن')"
                :message="__('سيوقف هذا كل مجموعة إعلانية مربوطة في النظام على كل الصفحات — لا الصفحات المُسلَّمة للطيّار وحدها. لن تُحذف، وتُعاد بتشغيلها من مدير الإعلانات.')" />
        @endif
    </div>

    {{-- ملخّص اليوم المالي: هو سبب وجود الطيّار لا أثرٌ جانبي له. --}}
    @if ($totals !== [])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-5">
            <x-admin.stat-card :label="__('المبيعات')" :value="$totals['sales']" money tone="blue"
                               :hint="__(':n طلبًا · :c محادثة', ['n' => $totals['orders'], 'c' => $totals['conversations']])" />
            <x-admin.stat-card :label="__('صرف الإعلان')" :value="$totals['spend']" money tone="amber" />
            <x-admin.stat-card :label="__('الربح بعد الإعلان')" :value="$totals['profit_after_ads']" money
                               :tone="$totals['profit_after_ads'] >= 0 ? 'green' : 'red'" />
            <x-admin.stat-card :label="__('الربح التشغيلي لليوم')" :value="$totals['operating_profit']" money
                               :tone="$totals['operating_profit'] >= 0 ? 'green' : 'red'"
                               :hint="__('بعد الإعلان والمصروف الثابت معًا')" />
        </div>
    @endif

    {{-- ملخّص ما فعله الطيّار — منفصلٌ عن أرقام العمل عمدًا. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
        <x-admin.stat-card :label="__('قرارات اليوم')" :value="$stats['total']" tone="gray"
                           :hint="__(':a نُفِّذ · :p مقترح', ['a' => $stats['applied'], 'p' => $stats['planned']])" />
        <x-admin.stat-card :label="__('إعلانات أُوقفت')" :value="$stats['paused']"
                           :tone="$stats['paused'] > 0 ? 'red' : 'gray'" />
        <x-admin.stat-card :label="__('ميزانيات خُفِّضت')" :value="$stats['decreased']"
                           :tone="$stats['decreased'] > 0 ? 'amber' : 'gray'" />
        <x-admin.stat-card :label="__('وفّر يوميًّا')" :value="$budget($stats['saved'])"
                           :tone="$stats['saved'] > 0 ? 'green' : 'gray'"
                           :hint="__('بعملة الحساب الإعلاني')" />
    </div>

    @if ($stats['failed'] > 0)
        <div class="mb-5">
            <x-admin.alert tone="red" :title="__('قرارات لم تصل المنصّة')">
                {{ trans_choice('قرارٌ واحد فشل إرساله — سببه في الجدول أدناه.|:count قرارات فشل إرسالها — أسبابها في الجدول أدناه.', $stats['failed'], ['count' => $stats['failed']]) }}
                <span class="block mt-1 text-xs">{{ __('لا يُعاد الإرسال تلقائيًّا: نداءٌ نجح ثم انقطع الاتصال يُنفَّذ مرّتين إن أُعيد. تُعاد المحاولة في دورة الغد بعد قراءة الحالة من جديد.') }}</span>
            </x-admin.alert>
        </div>
    @endif

    {{-- ═══ الإعدادات: السقف والوضع والصفحات ═══ --}}
    @if ($can)
        <form method="POST" action="{{ route('admin.marketing.autopilot.settings') }}" class="admin-card p-5 mb-6">
            @csrf @method('PUT')

            <h3 class="font-semibold text-gray-800 mb-1">{{ __('ضبط الطيّار') }}</h3>
            <p class="text-xs text-gray-500 mb-4">{{ __('كل هذه الأرقام من هنا لا من الخادم — عدّلها متى شئت وتسري في الدورة التالية.') }}</p>

            <div class="grid gap-5 lg:grid-cols-2">
                <div class="space-y-4">
                    <label class="flex items-start gap-3 rounded-xl border-2 p-3 cursor-pointer
                                  {{ $settings['enabled'] ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200' }}">
                        <input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])
                               class="mt-0.5 rounded text-emerald-600 focus:ring-emerald-500 w-5 h-5">
                        <span>
                            <span class="block font-semibold text-sm text-gray-800">{{ __('تشغيل الطيّار') }}</span>
                            <span class="block text-xs text-gray-500">{{ __('مفتاح الإطفاء الرئيسي — إطفاؤه يوقف كل قرار آلي فورًا، ولا يُعيد تشغيل ما أُوقف.') }}</span>
                        </span>
                    </label>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1.5">{{ __('وضع التشغيل') }}</label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ([
                                'suggest' => [__('اقتراح'), __('يكتب القرارات ولا يلمس المنصّة. ابدأ هنا واقرأ سجلّه أسبوعين.')],
                                'brake' => [__('فرملة'), __('ينفّذ الإيقاف والتخفيض وحدهما. لا يرفع ميزانية ولا يُنشئ حملة.')],
                            ] as $value => [$label, $hint])
                                <label class="flex items-start gap-2.5 rounded-xl border-2 p-3 cursor-pointer
                                              {{ $settings['mode'] === $value ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200' }}">
                                    <input type="radio" name="mode" value="{{ $value }}" @checked($settings['mode'] === $value)
                                           class="mt-0.5 text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-sm text-gray-800">{{ $label }}</span>
                                        <span class="block text-[11px] text-gray-500 leading-relaxed">{{ $hint }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label for="daily_cap" class="block text-xs text-gray-500 mb-1">
                            {{ __('السقف اليومي (بعملة الحساب الإعلاني :c)', ['c' => $adCurrency ?: '—']) }}
                        </label>
                        <input id="daily_cap" type="number" step="0.01" min="0" name="daily_cap"
                               value="{{ old('daily_cap', $settings['daily_cap']) }}"
                               class="w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="mt-1 text-[11px] text-gray-500 leading-relaxed">
                            {{ __('مجموع الميزانيات اليومية للمجموعات التي يديرها الطيّار لا يتجاوزه. وإن تجاوزه، يوقف الأقلّ ربحًا حتى ينزل تحته.') }}
                            {{ __('وصفرٌ معناه «بلا سقف مضبوط»: الفرملة تبقى تعمل — هي لا تزيد الصرف أبدًا — والسقف وحده لا يُطبَّق.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label for="max_decrease_pct" class="block text-xs text-gray-500 mb-1">{{ __('أقصى تخفيض %') }}</label>
                            <input id="max_decrease_pct" type="number" step="1" min="5" max="50" name="max_decrease_pct"
                                   value="{{ old('max_decrease_pct', $settings['max_decrease_pct']) }}"
                                   class="w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label for="cooldown_days" class="block text-xs text-gray-500 mb-1">{{ __('أيام التهدئة') }}</label>
                            <input id="cooldown_days" type="number" step="1" min="0" max="14" name="cooldown_days"
                                   value="{{ old('cooldown_days', $settings['cooldown_days']) }}"
                                   class="w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label for="min_budget" class="block text-xs text-gray-500 mb-1">{{ __('أدنى ميزانية') }}</label>
                            <input id="min_budget" type="number" step="0.01" min="0" name="min_budget"
                                   value="{{ old('min_budget', $settings['min_budget']) }}"
                                   class="w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-500 leading-relaxed">
                        {{ __('تعديل الميزانية بأكثر من ~20% يُعيد المجموعة إلى مرحلة التعلّم لدى المنصّة، فتعديلٌ يوميّ عنيف يُبقيها في التعلّم أبدًا — وهذا سبب الحدّين. أمّا ما ينزل تحت «أدنى ميزانية» فيُوقَف بدل أن يُخفَّض.') }}
                    </p>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1.5">{{ __('الصفحات التي يديرها الطيّار') }}</label>
                        <div class="space-y-1.5">
                            @foreach ($channels as $channel)
                                <label class="flex items-center gap-2.5 rounded-lg border p-2.5 cursor-pointer
                                              {{ $channel->autopilot_enabled ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200' }}
                                              {{ $channel->is_active ? '' : 'opacity-50' }}">
                                    <input type="checkbox" name="channels[]" value="{{ $channel->id }}"
                                           @checked($channel->autopilot_enabled) @disabled(! $channel->is_active)
                                           class="rounded text-emerald-600 focus:ring-emerald-500">
                                    <span class="text-sm text-gray-800">{{ $channel->name }}</span>
                                    @unless ($channel->is_active)
                                        <span class="text-[11px] text-gray-400">{{ __('(معطّلة)') }}</span>
                                    @endunless
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 pt-4 border-t flex items-center justify-end">
                <button type="submit" class="btn-primary">{{ __('حفظ الإعدادات') }}</button>
            </div>
        </form>
    @endif

    {{-- ═══ سجلّ القرارات ═══ --}}
    <h3 class="font-semibold text-gray-800 mb-1">{{ __('قرارات يوم :d', ['d' => $dayString]) }}</h3>
    <p class="text-xs text-gray-500 mb-3">
        {{ __('يُسجَّل الامتناع كما يُسجَّل الفعل: «لم أخفّض ولماذا» معلومةٌ تحتاجها، وبغيرها يبدو الصمت رضًا.') }}
    </p>

    <x-admin.table dense>
        <thead>
            <tr>
                <th>{{ __('الصنف / المجموعة') }}</th>
                <th>{{ __('الصفحة') }}</th>
                <th>{{ __('القرار') }}</th>
                <th>{{ __('الميزانية') }}</th>
                <th>{{ __('أرقام النافذة') }}</th>
                <th>{{ __('السبب') }}</th>
                <th>{{ __('الحالة') }}</th>
                <th class="w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($decisions as $decision)
                <tr>
                    <td>
                        <span class="font-medium text-gray-800">{{ $decision->product?->name ?? __('—') }}</span>
                        <span class="block text-[11px] text-gray-400">{{ $decision->external_name ?: $decision->external_id }}</span>
                    </td>
                    <td class="text-sm text-gray-600">{{ $decision->channel?->name ?? '—' }}</td>
                    <td>
                        <span class="text-sm font-semibold {{ $actionTones[$decision->action] ?? 'text-gray-500' }}">
                            {{ $decision->actionLabel() }}
                        </span>
                    </td>
                    <td class="text-sm tabular-nums whitespace-nowrap">
                        @if ($decision->action === AdAutopilotDecision::ACTION_DECREASE && $decision->budget_after !== null)
                            <span class="text-gray-400 line-through">{{ $budget($decision->budget_before) }}</span>
                            <span class="block text-gray-800 font-semibold">{{ $budget($decision->budget_after) }}</span>
                        @else
                            <span class="text-gray-600">{{ $budget($decision->budget_before) }}</span>
                        @endif
                    </td>
                    <td class="text-xs text-gray-500 tabular-nums whitespace-nowrap">
                        {{ __(':o طلب', ['o' => $decision->window_orders]) }} ·
                        {{ __('صرف :s', ['s' => number_format((float) $decision->window_spend, 2)]) }}
                        <span class="block {{ (float) $decision->window_net_profit < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                            {{ __('صافي :n', ['n' => number_format((float) $decision->window_net_profit, 2)]) }} {{ $currency }}
                        </span>
                    </td>
                    <td class="text-xs text-gray-600 max-w-md leading-relaxed">
                        {{ $decision->reason }}
                        @if ($decision->error)
                            <span class="block mt-1 text-rose-600">{{ $decision->error }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="rounded px-1.5 py-0.5 text-[11px] ring-1 {{ $statusTones[$decision->status] ?? '' }}">
                            {{ $decision->statusLabel() }}
                        </span>
                        @if ($decision->reverted_at)
                            <span class="block text-[11px] text-gray-400">{{ $decision->revertedBy?->name }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        @if ($can && $decision->isRevertible())
                            <x-admin.confirm
                                :action="route('admin.marketing.autopilot.revert', $decision)"
                                method="POST"
                                tone="amber"
                                :trigger="__('تراجع')"
                                :title="__('التراجع عن القرار')"
                                :confirm="__('تراجع')"
                                :message="__('سيُعاد ما كان بالضبط: :a', ['a' => $decision->action === AdAutopilotDecision::ACTION_PAUSE
                                    ? __('تشغيل المجموعة من جديد.')
                                    : __('إعادة الميزانية إلى :b', ['b' => $budget($decision->budget_before)])])" />
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا قرارات لهذا اليوم')"
                        :description="__('الطيّار يعمل كل صباح على أرقام أمس. وإن كان مطفأً أو بلا صفحات مُسلَّمة فلن يُسجَّل شيء — أو شغّله الآن لترى ما كان سيقرّره.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <p class="mt-4 text-xs text-gray-500 leading-relaxed">
        {{ __('الحكم نفسه المعروض في «الميزانية اليومية»، محسوبًا على نافذة :n أيام وعلى الربح الحقيقي بعد تكلفة الجملة والمرتجع — لا على «تحويلات» المنصّة.', ['n' => (int) $thresholds['window_days']]) }}
        {{ __('والطيّار لا يرفع ميزانيةً ولا يُنشئ حملةً في أي وضع: إيقافٌ خاطئ يكلّف ربح يوم ويُتراجَع عنه بنقرة، وزيادةٌ خاطئة تصرف طوال الليل ولا تُستردّ.') }}
        <a href="{{ route('admin.reports.ad_budget', ['day' => $dayString]) }}" class="font-semibold text-emerald-700 hover:underline">{{ __('افتح الميزانية اليومية لهذا اليوم') }}</a>
    </p>
</x-app-layout>
