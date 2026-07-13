<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $treasury->name }} <span class="text-gray-400 text-sm">({{ $treasury->code }})</span></h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-white shadow-sm rounded-lg p-4"><p class="text-2xl font-bold {{ $balance < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($balance, 2) }}</p><p class="text-xs text-gray-500 mt-1">{{ __('الرصيد الحالي') }}</p></div>
            <div class="bg-white shadow-sm rounded-lg p-4"><p class="text-lg font-bold text-gray-800">{{ number_format($treasury->opening_balance, 2) }}</p><p class="text-xs text-gray-500 mt-1">{{ __('افتتاحي') }}</p></div>
            <div class="bg-white shadow-sm rounded-lg p-4"><p class="text-lg font-mono text-gray-800">{{ $treasury->glAccount?->code ?? '—' }}</p><p class="text-xs text-gray-500 mt-1">{{ __('حساب GL') }}</p></div>
            <div class="bg-white shadow-sm rounded-lg p-4"><p class="text-lg font-bold text-gray-800">{{ $treasury->currency }}</p><p class="text-xs text-gray-500 mt-1">{{ __('العملة') }}</p></div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-700">{{ __('حركة الخزينة') }}</h3>
                <a href="{{ route('admin.accounting.finance_reports.treasury_statement', $treasury) }}" class="text-sm text-emerald-600 hover:underline">{{ __('كشف حساب مفصّل') }}</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr><th class="py-2 px-3">{{ __('القيد') }}</th><th class="py-2 px-3">{{ __('التاريخ') }}</th><th class="py-2 px-3">{{ __('البيان') }}</th><th class="py-2 px-3">{{ __('مدين') }}</th><th class="py-2 px-3">{{ __('دائن') }}</th></tr></thead>
                    <tbody class="divide-y">
                        @forelse ($movements as $m)
                            <tr>
                                <td class="py-2 px-3 font-mono text-xs">{{ $m->entry?->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $m->entry?->entry_date?->format('Y-m-d') }}</td>
                                <td class="py-2 px-3 text-gray-600">{{ $m->entry?->description }}</td>
                                <td class="py-2 px-3 text-emerald-700">{{ $m->debit > 0 ? number_format($m->debit, 2) : '' }}</td>
                                <td class="py-2 px-3 text-rose-600">{{ $m->credit > 0 ? number_format($m->credit, 2) : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا توجد حركة.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
