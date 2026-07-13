<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('التقارير المالية') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <a href="{{ route('admin.accounting.finance_reports.vouchers') }}" class="bg-white shadow-sm rounded-lg p-5 hover:shadow-md"><h3 class="font-semibold text-gray-800">{{ __('تقرير السندات') }}</h3><p class="text-xs text-gray-500 mt-1">{{ __('قبض/صرف/مصروف/إيراد/تحويل') }}</p></a>
            <a href="{{ route('admin.accounting.finance_reports.daily_cash') }}" class="bg-white shadow-sm rounded-lg p-5 hover:shadow-md"><h3 class="font-semibold text-gray-800">{{ __('حركة النقد اليومية') }}</h3></a>
            <a href="{{ route('admin.accounting.finance_reports.monthly_summary') }}" class="bg-white shadow-sm rounded-lg p-5 hover:shadow-md"><h3 class="font-semibold text-gray-800">{{ __('ملخّص المصروفات/الإيرادات') }}</h3></a>
        </div>
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('كشوف الخزائن والبنوك') }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach ($treasuries as $t)
                    <a href="{{ route('admin.accounting.finance_reports.treasury_statement', $t) }}" class="flex items-center justify-between border rounded-md px-3 py-2 hover:bg-gray-50">
                        <span class="text-sm text-gray-700">{{ $t->name }} <span class="text-gray-400 text-xs">({{ $t->type }})</span></span>
                        <span class="text-sm font-bold {{ $t->current_balance < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ number_format($t->current_balance, 2) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
