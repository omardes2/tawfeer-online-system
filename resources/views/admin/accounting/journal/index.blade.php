<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المحاسبة') }} — {{ __('قيود اليومية') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('قيود اليومية')">
                <div class="flex gap-2">
                    @can('accounting.accounts.view')
                        <a href="{{ route('admin.accounting.accounts.index') }}" class="inline-flex px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('دليل الحسابات') }}</a>
                    @endcan
                    @can('accounting.reports.view')
                        <a href="{{ route('admin.accounting.reports.trial_balance') }}" class="inline-flex px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('ميزان المراجعة') }}</a>
                    @endcan
                    @can('create', \App\Modules\Accounting\Models\JournalEntry::class)
                        <a href="{{ route('admin.accounting.journal.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('قيد جديد') }}</a>
                    @endcan
                </div>
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرقم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('التاريخ') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('البيان') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المصدر') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($entries as $e)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $e->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $e->entry_date?->toDateString() }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $e->description }}</td>
                                <td class="py-2 px-3 text-gray-400">{{ $e->source }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $e->status === 'posted' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $e->status === 'posted' ? __('مُرحّل') : __('مسودّة') }}</span>
                                </td>
                                <td class="py-2 px-3"><a href="{{ route('admin.accounting.journal.show', $e) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد قيود.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $entries->links() }}</div>
        </div>
    </div>
</x-app-layout>
