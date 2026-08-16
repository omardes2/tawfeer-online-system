<x-app-layout :title="__('المبيعات حسب الزبون')">
    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp
    <div class="report-no-print">
        <x-admin.header
            :title="__('المبيعات حسب الزبون')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('المبيعات حسب الزبون') => null]" />
    </div>

    @include('admin.reports.business._toolbar', ['title' => __('المبيعات حسب الزبون')])

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('الزبون') }}</th>
                <th class="text-start">{{ __('عدد الطلبات') }}</th>
                <th class="text-start">{{ __('سعر البيع') }}</th>
                <th class="text-start">{{ __('الربح') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                    <td class="text-start tabular-nums">{{ number_format($r['orders_count']) }}</td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($r['sales_total'], 2) }} {{ $sym }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $r['profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($r['profit'], 2) }} {{ $sym }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="!p-0">
                    <x-admin.empty-state :title="__('لا توجد مبيعات')" :description="__('لم تُسجّل أي مبيعات بعد.')" />
                </td></tr>
            @endforelse
        </tbody>
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
        'basisNote' => __('مجموع هذه الصفحة يطابق «المبيعات حسب المنتج» و«المبيعات حسب موظف المبيعات» لنفس الفترة — الثلاثة تُحتسب على قيمة البضاعة المباعة نفسها.'),
    ])
</x-app-layout>
