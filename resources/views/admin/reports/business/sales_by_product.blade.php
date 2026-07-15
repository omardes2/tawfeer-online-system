<x-app-layout :title="__('المبيعات حسب المنتج')">
    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp
    <x-admin.header
        :title="__('المبيعات حسب المنتج')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('المبيعات حسب المنتج') => null]" />

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('المنتج') }}</th>
                <th class="text-start">{{ __('الكمية المباعة') }}</th>
                <th class="text-start">{{ __('إجمالي البيع') }}</th>
                <th class="text-start">{{ __('متوسط سعر القطعة') }}</th>
                <th class="text-start">{{ __('الربح الإجمالي') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r['product'] }}</td>
                    <td class="text-start tabular-nums">{{ number_format($r['qty'], 2) }}</td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($r['sale_total'], 2) }} {{ $sym }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($r['avg_price'], 2) }} {{ $sym }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $r['profit'] < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($r['profit'], 2) }} {{ $sym }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="!p-0">
                    <x-admin.empty-state :title="__('لا توجد مبيعات')" :description="__('لم تُسجّل أي مبيعات بعد.')" />
                </td></tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <tfoot>
                <tr class="font-bold bg-gray-50">
                    <td>{{ __('الإجمالي') }}</td>
                    <td class="text-start tabular-nums">{{ number_format($totalQty, 2) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($totalSales, 2) }} {{ $sym }}</td>
                    <td class="text-start text-gray-300">—</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $totalProfit < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($totalProfit, 2) }} {{ $sym }}</td>
                </tr>
            </tfoot>
        @endif
    </x-admin.table>
</x-app-layout>
