<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('التحويلات بين الخزائن') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('التحويلات')">
                @can('accounting.transfers.create')<a href="{{ route('admin.accounting.transfers.create') }}" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('تحويل جديد') }}</a>@endcan
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr><th class="py-2 px-3">{{ __('الرقم') }}</th><th class="py-2 px-3">{{ __('التاريخ') }}</th><th class="py-2 px-3">{{ __('من') }}</th><th class="py-2 px-3">{{ __('إلى') }}</th><th class="py-2 px-3">{{ __('المبلغ') }}</th><th class="py-2 px-3">{{ __('الحالة') }}</th><th></th></tr></thead>
                    <tbody class="divide-y">
                        @forelse ($transfers as $t)
                            <tr>
                                <td class="py-2 px-3 font-mono text-xs">{{ $t->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $t->voucher_date->format('Y-m-d') }}</td>
                                <td class="py-2 px-3">{{ $t->treasury?->name }}</td>
                                <td class="py-2 px-3">{{ $t->counterTreasury?->name }}</td>
                                <td class="py-2 px-3 font-bold">{{ number_format($t->amount, 2) }}</td>
                                <td class="py-2 px-3"><x-accounting.status :status="$t->status" /></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.accounting.transfers.show', $t) }}" class="text-indigo-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('لا توجد تحويلات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $transfers->links() }}</div>
        </div>
    </div>
</x-app-layout>
