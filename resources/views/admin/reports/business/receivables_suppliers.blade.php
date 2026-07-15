<x-app-layout :title="__('كشف حساب الموردين')">
    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp
    <div class="report-no-print">
        <x-admin.header
            :title="__('كشف حساب الموردين')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('كشف حساب الموردين') => null]" />
    </div>

    @include('admin.reports.business._toolbar', ['title' => __('كشف حساب الموردين')])

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('المورد') }}</th>
                <th class="text-start">{{ __('المبلغ المستحق') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap {{ $r['due'] > 0 ? 'text-rose-600' : 'text-gray-500' }}">{{ number_format($r['due'], 2) }} {{ $sym }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="!p-0">
                    <x-admin.empty-state :title="__('لا يوجد موردون')" :description="__('لم يُسجّل أي مورد بعد.')" />
                </td></tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <tfoot>
                <tr class="font-bold bg-gray-50">
                    <td>{{ __('إجمالي المستحق') }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($totalDue, 2) }} {{ $sym }}</td>
                </tr>
            </tfoot>
        @endif
    </x-admin.table>
</x-app-layout>
