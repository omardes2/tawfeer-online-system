<x-app-layout :title="__('المبيعات حسب المدن والمناطق')">
    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <div class="report-no-print">
        <x-admin.header
            :title="__('المبيعات حسب المدن والمناطق')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('المبيعات حسب المدن والمناطق') => null]" />
    </div>

    @include('admin.reports.business._toolbar', ['title' => __('المبيعات حسب المدن والمناطق')])

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('المدينة') }}</th>
                <th>{{ __('المنطقة') }}</th>
                <th class="text-start">{{ __('عدد الطلبات') }}</th>
                <th class="text-start">{{ __('سعر البيع') }}</th>
                <th class="text-start">{{ __('الربح') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cities as $city)
                {{-- صفّ المدينة: مجموعُها بارزًا، ثم مناطقها تحته. --}}
                <tr class="bg-gray-50 font-bold text-gray-800">
                    <td>{{ $city['city'] }}</td>
                    <td class="text-gray-400 font-normal">{{ trans_choice(':n منطقة|:n مناطق', $city['areas']->count(), ['n' => $city['areas']->count()]) }}</td>
                    <td class="text-start tabular-nums">{{ number_format($city['orders_count']) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($city['sales_total'], 2) }} {{ $sym }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $city['profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($city['profit'], 2) }} {{ $sym }}</td>
                </tr>
                @foreach ($city['areas'] as $area)
                    <tr>
                        <td></td>
                        <td class="text-gray-700">{{ $area['area'] }}</td>
                        <td class="text-start tabular-nums text-gray-600">{{ number_format($area['orders_count']) }}</td>
                        <td class="text-start tabular-nums whitespace-nowrap text-gray-700">{{ number_format($area['sales_total'], 2) }} {{ $sym }}</td>
                        <td class="text-start tabular-nums whitespace-nowrap {{ $area['profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($area['profit'], 2) }} {{ $sym }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="5" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا توجد مبيعات')"
                        :description="__('لا مبيعات في هذه الفترة — جرّب نطاقًا زمنيًّا آخر.')" />
                </td></tr>
            @endforelse
        </tbody>
        @if ($cities->isNotEmpty())
            <tfoot>
                <tr class="font-bold bg-gray-100">
                    <td colspan="2">{{ __('الإجمالي') }}</td>
                    <td class="text-start tabular-nums">{{ number_format($totalOrders) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($totalSales, 2) }} {{ $sym }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $totalProfit < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($totalProfit, 2) }} {{ $sym }}</td>
                </tr>
            </tfoot>
        @endif
    </x-admin.table>

    @include('admin.reports.business._basis', [
        'basisNote' => __('المدينة والمنطقة من بيانات الطلب وقت إنشائه. الطلب يقع في منطقة واحدة، فمجموع الطلبات عبر المناطق يساوي طلبات الفترة. والطلب بلا منطقة أو بلا مدينة يظهر مجمَّعًا كي يطابق المجموع.'),
    ])
</x-app-layout>
