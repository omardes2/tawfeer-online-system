<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المحاسبة') }} — {{ __('ميزان المراجعة') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الحساب') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('مدين') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('دائن') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الرصيد') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="py-2 px-3 text-gray-700">{{ $row['account_code'] }} — {{ $row['account_name'] }}</td>
                                <td class="py-2 px-3">{{ number_format($row['debit'], 2) }}</td>
                                <td class="py-2 px-3">{{ number_format($row['credit'], 2) }}</td>
                                <td class="py-2 px-3 font-medium">{{ number_format($row['balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('لا توجد حركات مُرحّلة.') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="border-t-2 font-semibold">
                        <tr>
                            <td class="py-2 px-3">{{ __('الإجمالي') }}</td>
                            <td class="py-2 px-3">{{ number_format($totalDebit, 2) }}</td>
                            <td class="py-2 px-3">{{ number_format($totalCredit, 2) }}</td>
                            <td class="py-2 px-3 {{ abs($totalDebit - $totalCredit) < 0.01 ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ abs($totalDebit - $totalCredit) < 0.01 ? __('متوازن') : __('غير متوازن') }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
