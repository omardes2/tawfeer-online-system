<x-report.layout :title="__('تقرير السندات')" :range="$range" :exportable="true">
    <div class="bg-white shadow-sm rounded-lg p-5 overflow-x-auto">
        <table class="min-w-full text-sm text-right">
            <thead class="text-gray-500 border-b"><tr><th class="py-2 px-3">{{ __('الرقم') }}</th><th class="py-2 px-3">{{ __('النوع') }}</th><th class="py-2 px-3">{{ __('التاريخ') }}</th><th class="py-2 px-3">{{ __('الخزينة') }}</th><th class="py-2 px-3">{{ __('الطرف') }}</th><th class="py-2 px-3">{{ __('المبلغ') }}</th><th class="py-2 px-3">{{ __('الحالة') }}</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($vouchers as $v)
                    <tr>
                        <td class="py-2 px-3 font-mono text-xs">{{ $v->number }}</td>
                        <td class="py-2 px-3">{{ __('accounting.kind.'.$v->kind) }}</td>
                        <td class="py-2 px-3 text-gray-500">{{ $v->voucher_date->format('Y-m-d') }}</td>
                        <td class="py-2 px-3">{{ $v->treasury?->name }}</td>
                        <td class="py-2 px-3">{{ $v->party_name ?: $v->counterAccount?->name }}</td>
                        <td class="py-2 px-3 font-bold">{{ number_format($v->amount, 2) }}</td>
                        <td class="py-2 px-3"><x-accounting.status :status="$v->status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('لا توجد سندات.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4 no-print">{{ $vouchers->links() }}</div>
    </div>
</x-report.layout>
