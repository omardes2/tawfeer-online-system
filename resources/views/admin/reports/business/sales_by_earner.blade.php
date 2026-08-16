<x-app-layout :title="$reportTitle">
    @php
        $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
        // كمية بلا أصفار زائدة: «2» لا «2.00»، و«1.5» تبقى كما هي.
        $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    @endphp
    <div class="report-no-print">
        <x-admin.header
            :title="$reportTitle"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, $reportTitle => null]" />
    </div>

    @include('admin.reports.business._toolbar', ['title' => $reportTitle])

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ $personLabel }}</th>
                <th class="text-start">{{ __('عدد الطلبات') }}</th>
                <th class="text-start">{{ __('سعر البيع') }}</th>
                <th class="text-start">{{ __('الربح') }}</th>
            </tr>
        </thead>

        {{--
            صفّ لكل شخص، وتحته قائمة منسدلة بطلباته: رقم التتبّع والأصناف والكمية
            والبيع والربح لكل طلب. `tbody` مستقلّ لكل شخص كي يحصر نطاق Alpine في
            صفّيه (الملخّص والتفصيل) بلا حاويةٍ تكسر بنية الجدول.
        --}}
        @forelse ($rows as $r)
            <tbody x-data="{ open: false }">
                <tr class="cursor-pointer select-none hover:bg-gray-50" x-on:click="open = ! open">
                    <td class="font-medium {{ $r['unassigned'] ? 'text-gray-500' : 'text-gray-800' }}">
                        <span class="inline-flex items-center gap-2">
                            <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-150"
                                 x-bind:class="open && 'rotate-180'"
                                 fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                            {{ $r['name'] }}
                        </span>
                    </td>
                    <td class="text-start tabular-nums">{{ number_format($r['orders_count']) }}</td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($r['sales'], 2) }} {{ $sym }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $r['profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($r['profit'], 2) }} {{ $sym }}</td>
                </tr>
                <tr x-show="open" x-cloak>
                    <td colspan="4" class="!p-0 bg-gray-50">
                        <div class="p-3">
                            <table class="w-full text-xs bg-white rounded-lg overflow-hidden ring-1 ring-gray-200">
                                <thead>
                                    <tr class="bg-gray-100/70 text-gray-500">
                                        <th class="text-start font-medium py-2 px-2.5">{{ __('رقم الطلب') }}</th>
                                        <th class="text-start font-medium py-2 px-2.5">{{ __('رقم التتبع') }}</th>
                                        <th class="text-start font-medium py-2 px-2.5">{{ __('المنتج المباع') }}</th>
                                        <th class="text-start font-medium py-2 px-2.5">{{ __('الكمية') }}</th>
                                        <th class="text-start font-medium py-2 px-2.5">{{ __('سعر البيع') }}</th>
                                        <th class="text-start font-medium py-2 px-2.5">{{ __('الربح') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($r['orders'] as $o)
                                        <tr class="border-t border-gray-100 align-top">
                                            <td class="py-2 px-2.5 font-medium text-gray-700 whitespace-nowrap">{{ $o['number'] }}</td>
                                            <td class="py-2 px-2.5 tabular-nums text-gray-600 whitespace-nowrap">{{ $o['tracking'] ?: '—' }}</td>
                                            <td class="py-2 px-2.5 text-gray-700 space-y-0.5">
                                                @foreach ($o['items'] as $it)
                                                    <div>
                                                        {{ $it['name'] }}
                                                        @if ($it['options'])
                                                            <span class="text-gray-400">({{ $it['options'] }})</span>
                                                        @endif
                                                        <span class="text-gray-400 tabular-nums">× {{ $qty($it['qty']) }}</span>
                                                    </div>
                                                @endforeach
                                            </td>
                                            <td class="py-2 px-2.5 tabular-nums text-gray-700">{{ $qty($o['qty']) }}</td>
                                            <td class="py-2 px-2.5 tabular-nums text-gray-700 whitespace-nowrap">{{ number_format($o['sale'], 2) }} {{ $sym }}</td>
                                            <td class="py-2 px-2.5 tabular-nums whitespace-nowrap {{ $o['profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($o['profit'], 2) }} {{ $sym }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            </tbody>
        @empty
            <tbody>
                <tr><td colspan="4" class="!p-0">
                    <x-admin.empty-state :title="__('لا توجد مبيعات')" :description="$emptyDescription" />
                </td></tr>
            </tbody>
        @endforelse

        @if ($rows->isNotEmpty())
            <tfoot>
                <tr class="font-bold bg-gray-50">
                    <td>{{ __('الإجمالي') }}</td>
                    <td class="text-start tabular-nums">{{ number_format($totalOrders) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($totalSales, 2) }} {{ $sym }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $totalProfit < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($totalProfit, 2) }} {{ $sym }}</td>
                </tr>
            </tfoot>
        @endif
    </x-admin.table>

    @include('admin.reports.business._basis', [
        'basisNote' => $rows->contains('unassigned', true)
            ? __('صفّ «:unassigned» يضمّ طلبات المتجر الإلكتروني وما يُنشئه المدير، ووجودُه يجعل إجمالي هذه الصفحة مطابقًا لتقريري «المبيعات حسب الزبون» و«المبيعات حسب المنتج» لنفس الفترة. اضغط اسم :person لعرض تفصيل طلباته.', ['unassigned' => $unassignedLabel, 'person' => $personLabel])
            : __('التقرير مقصور على المحدَّدين في الفلتر، فمجموعه أقلّ من مبيعات الفترة. اضغط اسم :person لعرض تفصيل طلباته.', ['person' => $personLabel]),
    ])
</x-app-layout>
