<x-app-layout :title="__('الميزانية اليومية')">
    @php
        $can = auth()->user()->can('reports.ad_budget.manage');
        $rate = $thresholds['usd_rate'];
        $dayString = $day->toDateString();
        $query = fn (array $extra = []) => request()->fullUrlWithQuery($extra);

        $tones = [
            'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'blue' => 'bg-sky-50 text-sky-700 ring-sky-200',
            'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'red' => 'bg-rose-50 text-rose-700 ring-rose-200',
            'gray' => 'bg-gray-100 text-gray-500 ring-gray-200',
        ];
    @endphp

    <div class="report-no-print">
        <x-admin.header
            :title="__('الميزانية اليومية')"
            :description="__('ربح كل صنف على كل صفحة مقابل ما صُرف عليه إعلانيًّا.')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('الميزانية اليومية') => null]" />
    </div>

    {{--
        قناة بلا حساب بزنس لا تستقبل أي طلب: الإسناد كلّه يمرّ عبر ذلك الربط.
        فالتنبيه هنا لا في الإعدادات — هنا يُكتشف الخلل حين يبدو صنفٌ بلا مبيعات.
    --}}
    @if ($unlinkedChannels > 0 && $can)
        <div class="report-no-print mb-5">
            <x-admin.alert tone="amber" :title="__('قنوات غير مربوطة')">
                {{ trans_choice('قناة نشطة واحدة بلا حساب بزنس، فلا يُنسب إليها أي طلب.|:count قنوات نشطة بلا حساب بزنس، فلا يُنسب إليها أي طلب.', $unlinkedChannels, ['count' => $unlinkedChannels]) }}
                <a href="{{ route('admin.settings.ad_channels.index') }}" class="font-semibold underline">{{ __('اربطها الآن') }}</a>
            </x-admin.alert>
        </div>
    @endif

    {{--
        صفوف «بلا قناة» تُترك بلا تفسير فتُقرأ خللًا. لها سببان لا ثالث لهما،
        وأولهما هو الغالب بعد تفعيل الميزة مباشرةً.
    --}}
    @if ($can && $rows->contains('unassigned', true))
        <div class="report-no-print mb-5">
            <x-admin.alert tone="amber" :title="__('طلبات غير مُسنَدة إلى صفحة')">
                {{ __('هذه الطلبات لا تحمل قناة، فلا يمكن نسب صرفٍ إعلاني إليها.') }}
                <span class="block mt-1 text-xs">
                    {{ __('إن كانت طلبات سابقة لتفعيل الميزة، شغّل مرّة واحدة:') }}
                    <code class="px-1 py-0.5 rounded bg-amber-100 font-mono" dir="ltr">php artisan ads:backfill-order-channels</code>
                </span>
                <span class="block mt-1 text-xs">
                    {{ __('وإن كانت طلبات جديدة، فمنشئها غير مرتبط بحساب بزنس — راجع') }}
                    <a href="{{ route('admin.settings.ad_channels.index') }}" class="font-semibold underline">{{ __('قنوات الإعلان') }}</a>
                    {{ __('وبطاقة الموظف.') }}
                </span>
            </x-admin.alert>
        </div>
    @endif

    {{-- شريط اليوم: الافتراضي أمس لأن أرقام Meta تُنسخ في اليوم التالي. --}}
    <div class="admin-card p-4 mb-5 report-no-print">
        <form method="GET" action="{{ route('admin.reports.ad_budget') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('اليوم') }}</label>
                <div class="flex items-center gap-1">
                    <a href="{{ $query(['day' => $day->copy()->subDay()->toDateString()]) }}"
                       class="btn-secondary btn-sm" title="{{ __('اليوم السابق') }}">‹</a>
                    <input type="date" name="day" value="{{ $dayString }}" max="{{ now()->toDateString() }}"
                           onchange="this.form.submit()"
                           class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <a href="{{ $query(['day' => $day->copy()->addDay()->toDateString()]) }}"
                       class="btn-secondary btn-sm" title="{{ __('اليوم التالي') }}">›</a>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('القناة') }}</label>
                <select name="channel" onchange="this.form.submit()"
                        class="rounded-lg border-gray-300 text-sm min-w-[12rem] focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('كل القنوات') }}</option>
                    @foreach ($allChannels as $c)
                        <option value="{{ $c->id }}" @selected($channelId === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ms-auto flex items-end gap-2">
                <a href="{{ $query(['export' => 'csv']) }}" class="btn-secondary btn-sm">{{ __('تصدير Excel') }}</a>
                <button type="button" onclick="window.print()" class="btn-secondary btn-sm">{{ __('طباعة / PDF') }}</button>
            </div>
        </form>
        {{--
            الصياغة الأولى («الحكم محسوبٌ على 3 أيام») قُرئت على أن الصفحة كلّها
            لثلاثة أيام. الأرقام كلّها ليومٍ واحد؛ النافذة تخصّ عمود التقييم وحده.
        --}}
        <p class="mt-3 text-xs text-gray-500 leading-relaxed">
            <span class="font-medium text-gray-700">{{ __('كل أرقام هذه الصفحة ليوم :d وحده.', ['d' => $dayString]) }}</span>
            @if ($window_days > 1)
                {{ __('وعمود «التقييم» وحده ينظر إلى آخر :n أيام (:from ← :to)، لأن المحادثة تتحوّل إلى طلب بعد يوم أو يومين فالحكم على يومٍ واحد يظلم الإعلان الجديد.', [
                    'n' => $window_days, 'from' => $window_from->toDateString(), 'to' => $dayString,
                ]) }}
            @endif
        </p>
    </div>

    <div class="text-center mb-4">
        <h2 class="text-lg font-bold text-gray-800">{{ $company }}</h2>
        <p class="text-gray-600">{{ __('الميزانية اليومية') }}</p>
        <p class="text-xs text-gray-400 mt-1">{{ $dayString }}</p>
    </div>

    {{-- بطاقات اليوم — وهنا وحدها يدخل المصروف التشغيلي الثابت. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 mb-5">
        <x-admin.stat-card :label="__('المبيعات')" :value="$totals['sales']" money tone="blue"
                           :hint="__(':n طلبًا · :c محادثة', ['n' => $totals['orders'], 'c' => $totals['conversations']])" />
        <x-admin.stat-card :label="__('الربح قبل الإعلان')" :value="$totals['profit_before_ads']" money tone="green" />
        <x-admin.stat-card :label="__('صرف الإعلان')" :value="$totals['spend']" money tone="amber"
                           :hint="'$'.number_format($totals['spend_usd'], 2)" />
        <x-admin.stat-card :label="__('الربح بعد الإعلان')" :value="$totals['profit_after_ads']" money
                           :tone="$totals['profit_after_ads'] >= 0 ? 'green' : 'red'" />
        <x-admin.stat-card :label="__('المصروف التشغيلي الثابت')" :value="$totals['fixed_cost']" money tone="gray"
                           :hint="__('رواتب وإيجار وكهرباء — لا يُوزَّع على الأصناف')" />
        <x-admin.stat-card :label="__('الربح التشغيلي لليوم')" :value="$totals['operating_profit']" money
                           :tone="$totals['operating_profit'] >= 0 ? 'green' : 'red'"
                           :hint="__('بعد الإعلان والمصروف الثابت معًا')" />
    </div>

    @if ($can)
        {{--
            سعر الصرف: رقمٌ يتحرّك أسبوعيًّا، وكان محبوسًا في الإعدادات بلا شاشة
            تعدّله — فيبقى ما ضُبط أوّل مرّة ويُحتسب به كل صرفٍ جديد. وخطؤه
            يُضخّم تكلفة الطلب بالشيكل فيُوقَف إعلانٌ رابح.
        --}}
        <div class="admin-card p-4 mb-5 report-no-print" x-data="{ open: false }">
            <button type="button" x-on:click="open = ! open" class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                <svg class="w-4 h-4 text-gray-400 transition" :class="open && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                {{ __('سعر صرف الدولار') }}
                <span class="font-bold tabular-nums text-emerald-700">{{ rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') }}</span>
            </button>
            <form x-show="open" x-cloak method="POST" action="{{ route('admin.reports.ad_budget.usd_rate') }}"
                  class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="usd-rate" class="block text-xs text-gray-500 mb-1">{{ __('كم شيكلًا للدولار') }}</label>
                    <input id="usd-rate" type="number" step="0.0001" min="0.0001" name="usd_rate"
                           value="{{ rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') }}" required
                           class="w-32 rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <label class="flex items-center gap-2 pb-2 cursor-pointer">
                    <input type="checkbox" name="apply_day" value="{{ $dayString }}"
                           class="rounded text-emerald-600 focus:ring-emerald-500">
                    <span class="text-sm text-gray-700">{{ __('أعِد احتساب صفوف يوم :d بهذا السعر', ['d' => $dayString]) }}</span>
                </label>
                <button type="submit" class="btn-primary btn-sm">{{ __('حفظ') }}</button>
                <p class="w-full text-xs text-gray-400 leading-relaxed">
                    {{ __('السعر الجديد يسري على ما يُدخَل بعده. والصفوف المحفوظة تحتفظ بسعر يومها — ربح ذلك اليوم مثبَّت عليه — إلّا إن طلبتَ إعادة احتساب اليوم المعروض صراحةً.') }}
                </p>
            </form>
        </div>

        {{-- ضبط المصروف الثابت — بتاريخ سريان فلا يُعاد كتابة ربح الماضي. --}}
        <div class="admin-card p-4 mb-5 report-no-print" x-data="{ open: false }">
            <button type="button" x-on:click="open = ! open" class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                <svg class="w-4 h-4 text-gray-400 transition" :class="open && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                {{ __('تعديل المصروف التشغيلي اليومي') }}
            </button>
            <form x-show="open" x-cloak method="POST" action="{{ route('admin.reports.ad_budget.fixed_cost') }}"
                  class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('المصروف اليومي') }}</label>
                    <input type="number" step="0.01" min="0" name="amount" value="{{ number_format($totals['fixed_cost'], 2, '.', '') }}"
                           class="w-36 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('يسري من') }}</label>
                    <input type="date" name="effective_from" value="{{ $dayString }}"
                           class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <div class="flex-1 min-w-[14rem]">
                    <label class="block text-xs text-gray-500 mb-1">{{ __('ملاحظة') }}</label>
                    <input type="text" name="note" maxlength="255" placeholder="{{ __('رواتب وإيجار مستودع وكهرباء') }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <button type="submit" class="btn-primary btn-sm">{{ __('حفظ') }}</button>
                <p class="w-full text-xs text-gray-400">{{ __('القيمة الجديدة تسري من التاريخ المحدَّد فصاعدًا، ولا تُغيّر أرباح الأيام السابقة.') }}</p>
            </form>
        </div>
    @endif

    @if ($missing_days !== [])
        <div class="report-no-print mb-5">
            <x-admin.alert tone="amber" :title="__('أيام ناقصة الإدخال')">
                {{ __('حُجب الحكم على قنواتٍ لم يُدخَل صرفُ بعض أيام نافذتها — الصرف الناقص يجعل الصنف يبدو أربح ممّا هو.') }}
                <span class="block mt-1 text-xs">
                    @foreach ($missing_days as $cid => $days)
                        <a href="{{ route('admin.reports.ad_budget', ['day' => $days[0], 'channel' => $cid]) }}"
                           class="underline">{{ $allChannels->firstWhere('id', $cid)?->name ?? '#'.$cid }}</a>
                        <span class="text-gray-500">({{ implode('، ', $days) }})</span>@if (! $loop->last) — @endif
                    @endforeach
                </span>
            </x-admin.alert>
        </div>
    @endif

    {{--
        النماذج خارج الجدول ويُشار إليها بخاصّية `form`: النموذج لا يجوز أن يمتدّ
        عبر خلايا الصفّ، والتحرير في مكانه أقلّ احتكاكًا من فتح شاشة لكل صنف.
    --}}
    @if ($can)
        <div class="hidden">
            @foreach ($rows as $r)
                @continue($r['unassigned'])
                <form id="spend-{{ $r['channel_id'] }}-{{ $r['product_id'] }}" method="POST" action="{{ route('admin.reports.ad_budget.spend') }}">
                    @csrf
                    <input type="hidden" name="spend_date" value="{{ $dayString }}">
                    <input type="hidden" name="ad_channel_id" value="{{ $r['channel_id'] }}">
                    <input type="hidden" name="product_id" value="{{ $r['product_id'] }}">
                    {{--
                        سعر صرف **هذا الصفّ** لا الافتراضيّ: كان تعديلُ المحادثات
                        وحدها يدهس سعر اليوم المحفوظ بالافتراضي الحاليّ، فيتغيّر
                        ربح يومٍ مضى بلا أن يقصد أحد. والافتراضيّ للصفّ الجديد وحده.
                    --}}
                    <input type="hidden" name="fx_rate" value="{{ $r['fx_rate'] ?: $rate }}">
                </form>
            @endforeach
        </div>
    @endif

    <x-admin.table dense>
        <thead>
            <tr>
                <th>{{ __('الصنف') }}</th>
                <th>{{ __('القناة') }}</th>
                <th class="text-start">{{ __('الصرف $') }}</th>
                <th class="text-start">{{ __('المحادثات') }}</th>
                <th class="text-start">{{ __('الطلبات') }}</th>
                <th class="text-start">{{ __('التحويل') }}</th>
                <th class="text-start">{{ __('المبيعات') }}</th>
                <th class="text-start">{{ __('الربح قبل الإعلان') }}</th>
                <th class="text-start">{{ __('صافي الربح') }}</th>
                <th class="text-start">{{ __('تكلفة الطلب') }}</th>
                <th>{{ __('التقييم') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                @php $fid = 'spend-'.$r['channel_id'].'-'.$r['product_id']; @endphp
                <tr class="{{ $r['unassigned'] ? 'bg-gray-50/60' : '' }}">
                    <td class="font-medium text-gray-800">{{ $r['product'] }}</td>
                    <td class="text-gray-500">{{ $r['channel'] }}</td>

                    {{-- الخانتان المُدخَلتان يدويًّا: تُنسخان من صفّ المجموعة الإعلانية في Meta. --}}
                    <td class="text-start">
                        @if ($can && ! $r['unassigned'])
                            <input type="number" step="0.01" min="0" name="amount_usd" form="{{ $fid }}"
                                   value="{{ number_format($r['spend_usd'], 2, '.', '') }}"
                                   class="w-24 rounded-md border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                        @else
                            <span class="tabular-nums text-gray-400">{{ $r['spend_usd'] > 0 ? '$'.number_format($r['spend_usd'], 2) : '—' }}</span>
                        @endif
                        {{-- المنصّة تقول رقمًا آخر: يُعرَض ولا يدهس اليدويّ — القرار للمستخدم. --}}
                        @if ($r['conflict'])
                            <span class="block mt-1 text-[11px] text-amber-600" title="{{ __('قيمة المنصّة تختلف عمّا أُدخل يدويًّا') }}">
                                {{ __('المنصّة: $:s · :c محادثة', ['s' => number_format($r['platform_usd'], 2), 'c' => $r['platform_conversations']]) }}
                            </span>
                        @endif
                    </td>
                    <td class="text-start">
                        @if ($can && ! $r['unassigned'])
                            <div class="flex items-center gap-1">
                                <input type="number" step="1" min="0" name="conversations" form="{{ $fid }}"
                                       value="{{ $r['conversations'] }}"
                                       class="w-20 rounded-md border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                                <button type="submit" form="{{ $fid }}" class="btn-primary btn-sm" title="{{ __('حفظ') }}">{{ __('حفظ') }}</button>
                                {{-- الحذف لا التصفير: صفرٌ مُدخَل يعني «لم يُعلَن»، والغياب يعني «لم يُنسخ بعد». --}}
                                @if ($r['has_spend_row'])
                                    <x-admin.confirm
                                        :action="route('admin.reports.ad_budget.spend.destroy', $r['spend_id'])"
                                        method="DELETE"
                                        :trigger="__('حذف')"
                                        :message="__('حذف صرف «:p» على «:c» ليوم :d؟', ['p' => $r['product'], 'c' => $r['channel'], 'd' => $dayString])" />
                                @endif
                            </div>
                        @else
                            <span class="tabular-nums text-gray-400">{{ $r['conversations'] ?: '—' }}</span>
                        @endif
                    </td>

                    <td class="text-start tabular-nums">{{ number_format($r['orders']) }}</td>
                    <td class="text-start tabular-nums text-gray-500">
                        {{ $r['window']['conversion'] === null ? '—' : $r['window']['conversion'].'%' }}
                    </td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($r['sales'], 2) }} {{ $currency }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $r['profit_before_ads'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                        {{ number_format($r['profit_before_ads'], 2) }} {{ $currency }}
                    </td>
                    <td class="text-start tabular-nums whitespace-nowrap font-medium {{ $r['net_profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                        {{ number_format($r['net_profit'], 2) }} {{ $currency }}
                    </td>
                    <td class="text-start tabular-nums whitespace-nowrap">
                        {{ $r['cpa'] === null ? '—' : number_format($r['cpa'], 2).' '.$currency }}
                    </td>
                    <td>
                        <span class="inline-block rounded-md px-2 py-1 text-xs font-semibold ring-1 {{ $tones[$r['verdict']['tone']] }}"
                              title="{{ $r['verdict']['reason'] }}">{{ $r['verdict']['label'] }}</span>
                        <span class="block mt-1 text-[11px] text-gray-400 max-w-[18rem]">{{ $r['verdict']['reason'] }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا بيانات لهذا اليوم')"
                        :description="__('لم تُسجَّل مبيعات ولم يُدخَل صرف إعلاني في هذا اليوم. أضف صرف صنفٍ من النموذج أدناه.')" />
                </td></tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <tfoot>
                <tr class="font-bold bg-gray-50">
                    <td colspan="2">{{ __('الإجمالي') }}</td>
                    <td class="text-start tabular-nums">${{ number_format($totals['spend_usd'], 2) }}</td>
                    <td class="text-start tabular-nums">{{ number_format($totals['conversations']) }}</td>
                    {{-- عدد الطلبات المختلفة لا جمع العمود: الطلب ذو الصنفين معدود في صفّيهما. --}}
                    <td class="text-start tabular-nums">{{ number_format($totals['orders']) }}</td>
                    <td class="text-start text-gray-300">—</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($totals['sales'], 2) }} {{ $currency }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($totals['profit_before_ads'], 2) }} {{ $currency }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $totals['profit_after_ads'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                        {{ number_format($totals['profit_after_ads'], 2) }} {{ $currency }}
                    </td>
                    <td colspan="2" class="text-start text-gray-300">—</td>
                </tr>
            </tfoot>
        @endif
    </x-admin.table>

    {{-- إضافة صنفٍ لم يبع شيئًا: هذه بالذات حالة «صُرف بلا نتيجة» التي تُوقَف فورًا. --}}
    @if ($can)
        <div class="admin-card p-4 mt-5 report-no-print">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('إضافة صرف صنف') }}</h3>
            <form method="POST" action="{{ route('admin.reports.ad_budget.spend') }}"
                  x-data="{
                        items: {{ Illuminate\Support\Js::from($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku])) }},
                        query: '',
                        open: false,
                        selectedId: '',
                        norm(text) {
                            return (text ?? '').toString()
                                .replace(/[ً-ْـ]/g, '')
                                .replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
                                .toLowerCase().trim();
                        },
                        get matches() {
                            const q = this.norm(this.query);
                            const pool = q === '' ? this.items
                                : this.items.filter(i => this.norm(i.name).includes(q) || this.norm(i.sku).includes(q));

                            return pool.slice(0, 40);
                        },
                        pick(item) { this.selectedId = item.id; this.query = item.name; this.open = false; },
                  }"
                  x-on:keydown.escape="open = false"
                  class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="spend_date" value="{{ $dayString }}">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('القناة') }}</label>
                    <select name="ad_channel_id" required
                            class="rounded-lg border-gray-300 text-sm min-w-[11rem] focus:border-emerald-500 focus:ring-emerald-500">
                        @foreach ($allChannels->where('is_active', true) as $c)
                            <option value="{{ $c->id }}" @selected($channelId === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{--
                    بحثٌ بالكتابة لا قائمة منسدلة: الكتالوج يتجاوز المئة صنف،
                    والتمرير حتى «حزام استقامة الظهر» أبطأ من كتابة «حزام».
                    التطبيع يتسامح مع اختلاف الهمزة والتاء المربوطة والألف
                    المقصورة والتشكيل — «جهاز تنظيف الاذن» تجده بـ«الأذن».
                --}}
                <div class="relative">
                    <label class="block text-xs text-gray-500 mb-1">{{ __('الصنف') }}</label>
                    <input type="hidden" name="product_id" :value="selectedId">
                    <input type="text" x-model="query" autocomplete="off"
                           x-on:focus="open = true"
                           {{-- كل تعديل يدوي يُلغي الاختيار: نصٌّ لا يطابق صنفًا ليس اختيارًا. --}}
                           x-on:input="selectedId = ''; open = true"
                           x-on:click.outside="open = false"
                           placeholder="{{ __('اكتب اسم الصنف أو رمزه…') }}"
                           class="w-full min-w-[16rem] rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <ul x-show="open" x-cloak
                        class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto rounded-lg bg-white shadow-lg ring-1 ring-black/5 py-1">
                        <template x-for="item in matches" :key="item.id">
                            <li>
                                <button type="button" x-on:click="pick(item)"
                                        class="w-full text-start px-3 py-1.5 text-sm text-gray-700 hover:bg-emerald-50">
                                    <span x-text="item.name"></span>
                                    <span class="block text-[11px] text-gray-400 font-mono" x-text="item.sku"></span>
                                </button>
                            </li>
                        </template>
                        <li x-show="matches.length === 0" class="px-3 py-2 text-sm text-gray-400">
                            {{ __('لا صنف يطابق البحث.') }}
                        </li>
                    </ul>
                    {{-- تنبيهٌ صامت بدل رفضٍ من الخادم بعد ملء بقية الحقول. --}}
                    <p x-show="query !== '' && selectedId === ''" x-cloak class="mt-1 text-[11px] text-amber-600">
                        {{ __('اختر صنفًا من القائمة.') }}
                    </p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('الصرف $') }}</label>
                    <input type="number" step="0.01" min="0" name="amount_usd" value="0" required
                           class="w-28 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('المحادثات') }}</label>
                    <input type="number" step="1" min="0" name="conversations" value="0" required
                           class="w-24 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('سعر الصرف') }}</label>
                    <input type="number" step="0.0001" min="0.0001" name="fx_rate" value="{{ $rate }}" required
                           class="w-24 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <button type="submit" class="btn-primary btn-sm"
                        :disabled="selectedId === ''" :class="selectedId === '' && 'opacity-50 cursor-not-allowed'">
                    {{ __('حفظ') }}
                </button>
            </form>
        </div>
    @endif

    {{-- الملخّص المقلوب: أيّ صفحة تستحقّ التوسّع، لا أيّ صنف. --}}
    @if ($channels->isNotEmpty())
        <h3 class="font-semibold text-gray-800 mt-6 mb-3">{{ __('إجمالي كل قناة') }}</h3>
        <x-admin.table dense>
            <thead>
                <tr>
                    <th>{{ __('القناة') }}</th>
                    <th class="text-start">{{ __('الطلبات') }}</th>
                    <th class="text-start">{{ __('المحادثات') }}</th>
                    <th class="text-start">{{ __('المبيعات') }}</th>
                    <th class="text-start">{{ __('الربح قبل الإعلان') }}</th>
                    <th class="text-start">{{ __('الصرف') }}</th>
                    <th class="text-start">{{ __('صافي الربح') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($channels as $c)
                    <tr>
                        <td class="font-medium text-gray-800">{{ $c['channel'] }}</td>
                        <td class="text-start tabular-nums">{{ number_format($c['orders']) }}</td>
                        <td class="text-start tabular-nums">{{ number_format($c['conversations']) }}</td>
                        <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($c['sales'], 2) }} {{ $currency }}</td>
                        <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($c['profit_before_ads'], 2) }} {{ $currency }}</td>
                        <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($c['spend'], 2) }} {{ $currency }}</td>
                        <td class="text-start tabular-nums whitespace-nowrap font-medium {{ $c['net_profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                            {{ number_format($c['net_profit'], 2) }} {{ $currency }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin.table>
    @endif

    <div class="admin-card p-4 mt-5 text-xs text-gray-500 leading-relaxed">
        <p class="font-semibold text-gray-700 mb-1">{{ __('أساس الاحتساب') }}</p>
        <p>{{ __('الربح = قيمة البضاعة بعد الخصم ناقص تكلفتها — بلا رسوم توصيل وبلا عمولات. الاحتساب على الطلبات المُدخَلة لا المسلَّمة، ويُستبعَد الطلب المرتجع وتُخصَم الكميات المرتجعة عند تسجيلها.') }}</p>
        <p class="mt-1">{{ __('صافي الربح في صفّ الصنف بعد تكلفة الإعلان وحدها — المصروف التشغيلي الثابت لا يُوزَّع على الأصناف، فهو لا يتغيّر بإيقاف إعلانٍ أو زيادته، ومكانُه بطاقة اليوم.') }}</p>
        <p class="mt-1">{{ __('العتبات: أقلّ من :a زد · دون :b ثبّت · دون :c أنقص · فوقها أوقف. ولا يصدر حكمٌ دون :m طلبات في النافذة، إلّا عند صرفٍ بلا محادثة فيُوقَف فورًا.', [
            'a' => $thresholds['increase_below'], 'b' => $thresholds['hold_below'],
            'c' => $thresholds['reduce_below'], 'm' => $thresholds['min_orders'],
        ]) }}</p>
        <p class="mt-1">{{ __('البنود الحرّة (بلا صنف من الكتالوج) لا تظهر في الصفوف لأنها لا تُنسب إلى صنفٍ يُعلَن عليه، لكنّ طلباتها معدودة في إجمالي اليوم.') }}</p>
    </div>

    <style>@media print { aside, .report-no-print, .admin-topbar { display: none !important; } .admin-card { box-shadow: none !important; } }</style>
</x-app-layout>
