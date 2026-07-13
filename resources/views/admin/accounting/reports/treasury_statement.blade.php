<x-report.layout :title="__('كشف حساب').' — '.$treasury->name" :range="$range">
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-white shadow-sm rounded-lg p-4"><p class="text-lg font-bold">{{ number_format($opening, 2) }}</p><p class="text-xs text-gray-500">{{ __('رصيد أول المدّة') }}</p></div>
        <div class="bg-white shadow-sm rounded-lg p-4"><p class="text-lg font-bold text-emerald-700">{{ number_format($closing, 2) }}</p><p class="text-xs text-gray-500">{{ __('الرصيد الحالي') }}</p></div>
    </div>
    <div class="bg-white shadow-sm rounded-lg p-5 overflow-x-auto">
        <table class="min-w-full text-sm text-right">
            <thead class="text-gray-500 border-b"><tr><th class="py-2 px-3">{{ __('التاريخ') }}</th><th class="py-2 px-3">{{ __('القيد') }}</th><th class="py-2 px-3">{{ __('البيان') }}</th><th class="py-2 px-3">{{ __('مدين') }}</th><th class="py-2 px-3">{{ __('دائن') }}</th><th class="py-2 px-3">{{ __('الرصيد') }}</th></tr></thead>
            <tbody class="divide-y">
                @php $run = $opening; @endphp
                <tr class="bg-gray-50"><td colspan="5" class="py-2 px-3 text-gray-500">{{ __('رصيد أول المدّة') }}</td><td class="py-2 px-3 font-bold">{{ number_format($run, 2) }}</td></tr>
                @forelse ($lines as $l)
                    @php $run += (float) $l->debit - (float) $l->credit; @endphp
                    <tr>
                        <td class="py-2 px-3 text-gray-500">{{ $l->entry?->entry_date?->format('Y-m-d') }}</td>
                        <td class="py-2 px-3 font-mono text-xs">{{ $l->entry?->number }}</td>
                        <td class="py-2 px-3 text-gray-600">{{ $l->entry?->description }}</td>
                        <td class="py-2 px-3 text-emerald-700">{{ $l->debit > 0 ? number_format($l->debit, 2) : '' }}</td>
                        <td class="py-2 px-3 text-rose-600">{{ $l->credit > 0 ? number_format($l->credit, 2) : '' }}</td>
                        <td class="py-2 px-3 font-bold">{{ number_format($run, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا حركة في المدّة.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-report.layout>
