<x-app-layout :title="__('لوحة قرار الصنف')">
    @php
        $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
        $tones = [
            'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'red' => 'bg-rose-50 text-rose-700 ring-rose-200',
            'gray' => 'bg-gray-100 text-gray-500 ring-gray-200',
        ];
        $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    @endphp

    <div class="report-no-print">
        <x-admin.header
            :title="__('لوحة قرار الصنف')"
            :description="__('ماذا يربح كل صنف بعد الإعلان والتوصيل، وهل يكفي مخزونه حتى تصل الشحنة القادمة.')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('لوحة قرار الصنف') => null]" />

        <x-admin.flash />
    </div>

    @include('admin.reports.business._toolbar', ['title' => __('لوحة قرار الصنف')])

    {{-- الملخّص: أين ذهب المال فعلًا --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('المبيعات')" :value="$totals['sales']" money tone="blue" />
        <x-admin.stat-card :label="__('صرف الإعلان')" :value="$totals['ad_spend']" money tone="amber" />
        <x-admin.stat-card :label="__('صافي التوصيل')" :value="$totals['delivery']" money tone="amber"
                           :hint="__('المدفوع :cost − المُحصَّل :revenue', [
                               'cost' => number_format($totals['delivery_cost'], 2),
                               'revenue' => number_format($totals['delivery_revenue'], 2),
                           ])" />
        <x-admin.stat-card :label="__('صافي الربح')" :value="$totals['net_profit']" money
                           :tone="$totals['net_profit'] < 0 ? 'red' : 'green'"
                           :hint="__('بعد تكلفة البضاعة والإعلان والتوصيل')" />
    </div>

    {{-- إعدادات التخطيط: رقمان يحكمان كل تنبيهات النفاد والكميات المقترحة --}}
    @can('reports.ad_budget.manage')
        <details class="admin-card admin-card-pad mb-5 report-no-print">
            <summary class="cursor-pointer font-semibold text-gray-800">
                {{ __('إعدادات التخطيط') }}
                <span class="ms-2 text-xs font-normal text-gray-500">
                    {{ __('مهلة التوريد :l يومًا · مخزون أمان :s يومًا', ['l' => $plan['lead_time_days'], 's' => $plan['safety_days']]) }}
                </span>
            </summary>
            <form method="POST" action="{{ route('admin.reports.product_decision.planning') }}" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <x-admin.field :label="__('مهلة التوريد (يوم)')" name="lead_time_days"
                               :hint="__('من طلب البضاعة حتى وصولها الرفّ — الاستيراد بالكونتينر شهور.')">
                    <input type="number" name="lead_time_days" value="{{ $plan['lead_time_days'] }}" min="1" max="365" required
                           class="w-32 rounded-lg border-gray-300 text-sm" />
                </x-admin.field>
                <x-admin.field :label="__('مخزون أمان (يوم)')" name="safety_days"
                               :hint="__('هامشٌ فوق المهلة — التأخير قاعدة لا استثناء.')">
                    <input type="number" name="safety_days" value="{{ $plan['safety_days'] }}" min="0" max="180" required
                           class="w-32 rounded-lg border-gray-300 text-sm" />
                </x-admin.field>
                <button class="btn-primary btn-sm">{{ __('حفظ') }}</button>
            </form>
        </details>
    @endcan

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('الصنف') }}</th>
                <th class="text-center">{{ __('الحكم') }}</th>
                <th class="text-start">{{ __('المبيعات') }}</th>
                <th class="text-start">{{ __('التكلفة') }}</th>
                <th class="text-start">{{ __('الإعلان') }}</th>
                <th class="text-start">{{ __('التوصيل (صافي)') }}</th>
                <th class="text-start">{{ __('صافي الربح') }}</th>
                <th class="text-center">{{ __('الارتجاع') }}</th>
                <th class="text-center">{{ __('المتوفّر') }}</th>
                <th class="text-center">{{ __('يكفي') }}</th>
                <th class="text-center">{{ __('في الطريق') }}</th>
                <th class="text-center">{{ __('اطلب') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">
                        {{ $r['product'] }}
                        <span class="block text-[11px] text-gray-400 font-mono">{{ $r['sku'] }}</span>
                        {{-- سببُ الحكم تحت الاسم: الرقم بلا سببه لا يُتَّخذ عليه قرار --}}
                        <span class="block mt-0.5 text-[11px] text-gray-500">{{ $r['verdict']['note'] }}</span>
                    </td>
                    <td class="text-center">
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 whitespace-nowrap {{ $tones[$r['verdict']['tone']] }}">
                            {{ $r['verdict']['label'] }}
                        </span>
                    </td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($r['sales'], 2) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap text-gray-500">{{ number_format($r['cogs'], 2) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $r['ad_spend'] > 0 ? 'text-amber-700' : 'text-gray-300' }}">{{ number_format($r['ad_spend'], 2) }}</td>
                    {{--
                        صافي التوصيل: المدفوع للشركة ناقص المُحصَّل من الزبون.
                        موجبٌ = يكلّفك (كهرمان) · سالبٌ = تربح منه (أخضر).
                        والطرفان في `title` لمن أراد التفصيل.
                    --}}
                    <td class="text-start tabular-nums whitespace-nowrap {{ $r['delivery_net'] > 0 ? 'text-amber-700' : ($r['delivery_net'] < 0 ? 'text-emerald-700' : 'text-gray-300') }}"
                        title="{{ __('المدفوع') }}: {{ number_format($r['delivery_cost'], 2) }} · {{ __('المُحصَّل') }}: {{ number_format($r['delivery_revenue'], 2) }}">{{ number_format($r['delivery_net'], 2) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap font-bold {{ $r['net_profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                        {{ number_format($r['net_profit'], 2) }}
                        @if ($r['margin_pct'] !== null)
                            <span class="block text-[11px] font-normal text-gray-400">{{ $r['margin_pct'] }}%</span>
                        @endif
                    </td>
                    <td class="text-center tabular-nums text-xs {{ $r['return_rate'] >= 10 ? 'text-rose-600 font-bold' : 'text-gray-500' }}">
                        {{ $r['return_rate'] }}%
                    </td>
                    <td class="text-center tabular-nums">{{ $qty($r['available']) }}</td>
                    <td class="text-center tabular-nums whitespace-nowrap">
                        @if ($r['days_of_cover'] === null)
                            <span class="text-gray-300">—</span>
                        @else
                            <span class="{{ $r['days_of_cover'] < $plan['lead_time_days'] ? 'text-amber-700 font-bold' : 'text-gray-700' }}">
                                {{ $r['days_of_cover'] }} {{ __('يوم') }}
                            </span>
                        @endif
                    </td>
                    <td class="text-center tabular-nums {{ $r['incoming'] > 0 ? 'text-sky-700' : 'text-gray-300' }}">{{ $qty($r['incoming']) }}</td>
                    <td class="text-center tabular-nums font-bold {{ $r['suggested_qty'] > 0 ? 'text-emerald-700' : 'text-gray-300' }}">
                        {{ $r['suggested_qty'] > 0 ? $qty($r['suggested_qty']) : '—' }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="12" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا بيانات في هذه الفترة')"
                        :description="__('لم يُبَع صنف ولم يُصرَف إعلان في النطاق المحدّد — جرّب فترة أخرى.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    @include('admin.reports.business._basis', [
        'basisIncludes' => [
            __('سعر بيع البضاعة بعد الخصم وصافي المرتجع'),
            __('تكلفة شراء البضاعة'),
            __('صرف الإعلان المُدخَل على الصنف نفسه'),
            __('صافي التوصيل = المدفوع لشركة التوصيل − المُحصَّل من الزبون، كلاهما موزَّعٌ بحصّة الصنف من قيمة الطلب. موجبٌ يعني أنك تدعم التوصيل، وسالبٌ يعني أنك تربح منه'),
        ],
        'basisExcludes' => [
            __('عمولات الموظفين والمسوّقين'),
            __('الضريبة'),
            __('المصروف التشغيلي الثابت (رواتب وإيجار)'),
            __('الطلبات المسودّة والجديدة والملغاة'),
        ],
        'basisNote' => __('«يكفي» = المتوفّر ÷ متوسط البيع اليومي في الفترة المختارة، ففترةٌ قصيرة أو موسمٌ استثنائي يغيّران الرقم. و«اطلب» = ما يغطّي مهلة التوريد ومخزون الأمان ناقصًا المتوفّر وما في الطريق. وتكلفة توصيل الطلب المرتجَع تبقى محسوبةً على الصنف لأنها دُفعت فعلًا — وهذا ما يكشف أن المرتجعات تأكل الربح.'),
    ])
</x-app-layout>
