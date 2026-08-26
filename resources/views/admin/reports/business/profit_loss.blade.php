<x-app-layout :title="__('الأرباح والخسائر')">
    <div class="report-no-print">
        <x-admin.header
            :title="__('الأرباح والخسائر')"
            :description="__('الإيرادات وتكلفة البضاعة والمصاريف حتى صافي الدخل — للفترة المحدّدة.')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('الأرباح والخسائر') => null]" />

        <x-admin.flash />
    </div>

    @include('admin.reports.business._toolbar', ['title' => __('الأرباح والخسائر')])

    {{-- الأرقام الأربعة التي يُقرأ منها الحكم قبل النزول إلى التفصيل. --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('إجمالي الإيرادات')" :value="$report['revenue']['total']" money tone="blue" />
        <x-admin.stat-card :label="__('مجمل الربح')" :value="$report['gross_profit']" money tone="gray"
                           :hint="$report['gross_margin'] === null ? null : __('الهامش :p%', ['p' => $report['gross_margin']])" />
        <x-admin.stat-card :label="__('صافي ربح التوصيل')" :value="$report['delivery']['net']" money
                           :tone="$report['delivery']['net'] > 0 ? 'green' : 'gray'"
                           :hint="__('مُحصَّل :c − مدفوع :p', ['c' => number_format($report['delivery']['collected'], 2), 'p' => number_format($report['delivery']['paid'], 2)])" />
        <x-admin.stat-card :label="__('إجمالي المصاريف')" :value="$report['expenses']['total']" money tone="amber" />
        <x-admin.stat-card :label="__('صافي الدخل')" :value="$report['net_income']" money
                           :tone="$report['net_income'] >= 0 ? 'green' : 'red'"
                           :hint="$report['net_margin'] === null ? null : __('الهامش :p%', ['p' => $report['net_margin']])" />
    </div>

    @php
        // صفٌّ واحد بثلاث هيئات: عاديّ، ومجموعٍ فرعيّ، ونتيجةٍ نهائية.
        $money = fn ($v) => number_format((float) $v, 2);
    @endphp

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('ملخص') }}</th>
                        <th class="text-start w-48">{{ __('إجمالي') }}</th>
                    </tr>
                </thead>

                {{-- ① الإيرادات: أربعة أقسامٍ تجمع إلى مبيعات البضاعة، ثم رسوم التوصيل. --}}
                <tbody class="border-t-4 border-gray-100">
                    <tr class="bg-gray-50">
                        <th colspan="2" class="text-start font-bold text-gray-700">{{ __('الإيرادات') }}</th>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('مبيعات الموظفين') }}</td>
                        <td class="text-start tabular-nums">{{ $money($report['revenue']['staff']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('المبيعات المباشرة') }}</td>
                        <td class="text-start tabular-nums">{{ $money($report['revenue']['direct']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('مبيعات المسوّقين') }}</td>
                        <td class="text-start tabular-nums">{{ $money($report['revenue']['affiliates']) }}</td>
                    </tr>
                    {{-- طلبات المتجر بلا بائعٍ مُسنَد. يظهر السطر ولو كان صفرًا حين
                         وُجدت مبيعاتٌ أخرى: غيابُه يجعل الأقسام لا تجمع إلى الإجمالي. --}}
                    <tr>
                        <td class="ps-8">{{ __('مبيعات المتجر') }}
                            <span class="text-[11px] text-gray-400">{{ __('(بلا بائع مُسنَد)') }}</span>
                        </td>
                        <td class="text-start tabular-nums">{{ $money($report['revenue']['store']) }}</td>
                    </tr>
                    <tr class="bg-emerald-50 font-bold text-emerald-800">
                        <td>{{ __('إجمالي الإيرادات') }}
                            <span class="text-[11px] font-normal text-emerald-600">{{ __('(بلا رسوم التوصيل)') }}</span>
                        </td>
                        <td class="text-start tabular-nums">{{ $money($report['revenue']['total']) }}</td>
                    </tr>
                </tbody>

                {{-- ② تكلفة البضاعة المباعة ثم مجمل الربح. --}}
                <tbody class="border-t-4 border-gray-100">
                    <tr class="bg-gray-50">
                        <th colspan="2" class="text-start font-bold text-gray-700">{{ __('تكلفة البضاعة المباعة') }}</th>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('تكلفة البضاعة المباعة') }}
                            <span class="text-[11px] text-gray-400">{{ __('(بسعر الشراء المُجمَّد وقت البيع)') }}</span>
                        </td>
                        <td class="text-start tabular-nums">({{ $money($report['cogs']) }})</td>
                    </tr>
                    <tr class="bg-emerald-50 font-bold text-emerald-800">
                        <td>{{ __('مجمل الربح') }}</td>
                        <td class="text-start tabular-nums">
                            {{ $money($report['gross_profit']) }}
                            @if ($report['gross_margin'] !== null)
                                <span class="text-[11px] font-normal text-emerald-600">{{ $report['gross_margin'] }}%</span>
                            @endif
                        </td>
                    </tr>
                </tbody>

                {{-- ③ صافي ربح التوصيل — خدمةٌ بيعت بأكثر من كلفتها. --}}
                <tbody class="border-t-4 border-gray-100">
                    <tr class="bg-gray-50">
                        <th colspan="2" class="text-start font-bold text-gray-700">{{ __('صافي ربح التوصيل') }}</th>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('رسوم التوصيل المُحصَّلة من الزبائن') }}</td>
                        <td class="text-start tabular-nums">{{ $money($report['delivery']['collected']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('المدفوع لشركة التوصيل') }}</td>
                        <td class="text-start tabular-nums">({{ $money($report['delivery']['paid']) }})</td>
                    </tr>
                    <tr class="font-bold {{ $report['delivery']['net'] >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800' }}">
                        <td>{{ __('صافي ربح التوصيل') }}</td>
                        <td class="text-start tabular-nums">{{ $money($report['delivery']['net']) }}</td>
                    </tr>
                </tbody>

                {{-- ④ المصاريف: الثلاثة الثابتة ثم تصنيفات سندات الصرف. --}}
                <tbody class="border-t-4 border-gray-100">
                    <tr class="bg-gray-50">
                        <th colspan="2" class="text-start font-bold text-gray-700">{{ __('المصاريف') }}</th>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('الإعلانات') }}
                            <span class="text-[11px] text-gray-400">{{ __('(من جدول الصرف الإعلاني)') }}</span>
                        </td>
                        <td class="text-start tabular-nums">({{ $money($report['expenses']['ads']) }})</td>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('عمولات المبيعات والتسويق') }}
                            <span class="text-[11px] text-gray-400">{{ __('(المستحقّة في الفترة)') }}</span>
                        </td>
                        <td class="text-start tabular-nums">({{ $money($report['expenses']['commissions']) }})</td>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('الرواتب والأجور') }}
                            <span class="text-[11px] text-gray-400">{{ __('(من مسيّرات الرواتب المُرحَّلة)') }}</span>
                        </td>
                        <td class="text-start tabular-nums">({{ $money($report['expenses']['payroll']) }})</td>
                    </tr>
                    <tr>
                        <td class="ps-8">{{ __('مكافأة نهاية الخدمة') }}
                            <span class="text-[11px] text-gray-400">{{ __('(المخصّص المتراكم في الفترة)') }}</span>
                        </td>
                        <td class="text-start tabular-nums">({{ $money($report['expenses']['end_of_service']) }})</td>
                    </tr>

                    @forelse ($report['expenses']['categories'] as $category)
                        <tr>
                            <td class="ps-8">{{ $category['name'] }}</td>
                            <td class="text-start tabular-nums">({{ $money($category['total']) }})</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="ps-8 text-sm text-gray-400">
                                {{ __('لا سندات صرف مُرحَّلة في هذه الفترة.') }}
                            </td>
                        </tr>
                    @endforelse

                    <tr class="bg-amber-50 font-bold text-amber-800">
                        <td>{{ __('إجمالي المصاريف') }}</td>
                        <td class="text-start tabular-nums">({{ $money($report['expenses']['total']) }})</td>
                    </tr>
                </tbody>

                {{-- ⑤ النتيجة. --}}
                <tbody class="border-t-4 border-gray-100">
                    <tr class="{{ $report['net_income'] >= 0 ? 'bg-emerald-100 text-emerald-900' : 'bg-red-100 text-red-900' }} font-bold text-base">
                        <td>{{ $report['net_income'] >= 0 ? __('صافي الدخل') : __('صافي الخسارة') }}</td>
                        <td class="text-start tabular-nums">
                            {{ $money($report['net_income']) }}
                            @if ($report['net_margin'] !== null)
                                <span class="text-[11px] font-normal">{{ $report['net_margin'] }}%</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{--
        حدود القائمة، مكتوبةً في الصفحة لا في الوثائق: من يقرأ الرقم هو من يجب
        أن يعرف ما لم يدخل فيه.
    --}}
    <div class="admin-card admin-card-pad mt-5 text-sm text-gray-600 leading-7">
        <h3 class="font-semibold text-gray-800 mb-2">{{ __('كيف قُرئت الأرقام') }}</h3>
        <ul class="list-disc ps-5 space-y-1">
            <li>{{ __('المبيعات والتكلفة من بنود الفواتير لا من إجمالي الطلب، والمرتجع الجزئيّ يُخصَم من الاثنين بنسبته.') }}</li>
            <li>{{ __('الطلبات الملغاة والمحذوفة خارج القائمة.') }}</li>
            <li>{{ __('طلبٌ لمسوّقٍ ومُسنَدٌ لموظف يُحتسب للمسوّق وحده — فلا يُعدّ مرّتين.') }}</li>
            <li>{{ __('العمولة تُحتسب باستحقاق الفترة سواءٌ صُرفت أم لا، ودفعاتها لا تتكرّر في سندات الصرف.') }}</li>
            <li>{{ __('الإعلانات تُقرأ من جدول الصرف الإعلاني — وهو خارج القيود المحاسبية، فلا يظهر في ميزان المراجعة.') }}</li>
            <li>{{ __('الرواتب ومكافأة نهاية الخدمة تُقرآن من قيود المسيّرات المُرحَّلة — فالمسودّة لا تدخل، والمعكوس يُلغي نفسه.') }}</li>
            <li>
                {{ __('التوصيل يدخل بصافيه لا بطرفيه: رسومُه ليست إيرادًا ولا أجرتُه مصروفًا، بل الفرق بينهما ربحُ خدمةٍ بيعت بأكثر من كلفتها.') }}
                <a href="{{ route('admin.reports.delivery_cost') }}" class="text-emerald-600 hover:underline">{{ __('تقرير تكلفة التوصيل') }}</a>
                {{ __('يعرض المدفوع للشركة على حدة.') }}
            </li>
            <li>{{ __('ومجمل الربح على البضاعة وحدها — هامش التوصيل خدمةٌ لا بضاعة، وضمُّه يُفسد الهامش الذي تُقاس به قرارات الشراء.') }}</li>
        </ul>
    </div>
</x-app-layout>
