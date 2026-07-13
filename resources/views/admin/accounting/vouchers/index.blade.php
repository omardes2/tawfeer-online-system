<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('accounting.kind.'.$kind) }}</h2></x-slot>
    <div class="py-8 max-w-6xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('accounting.kind.'.$kind)">
                <div class="flex gap-2">
                    <a href="{{ route('admin.accounting.vouchers.export', array_merge(['kind' => $kind], $filters)) }}" class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('تصدير Excel') }}</a>
                    @can('accounting.'.($kind === 'income' ? 'income' : $kind.'s').'.create')
                        <a href="{{ route('admin.accounting.vouchers.create', $kind) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('جديد') }}</a>
                    @endcan
                </div>
            </x-admin.header>

            <form method="GET" class="grid grid-cols-1 sm:grid-cols-5 gap-2 mb-4">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('رقم / طرف') }}" class="rounded-md border-gray-300 text-sm" />
                <select name="status" class="rounded-md border-gray-300 text-sm">
                    <option value="">{{ __('كل الحالات') }}</option>
                    @foreach (['draft', 'approved', 'posted', 'reversed', 'cancelled', 'rejected'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('accounting.status.'.$s) }}</option>@endforeach
                </select>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="rounded-md border-gray-300 text-sm" />
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="rounded-md border-gray-300 text-sm" />
                <button class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('تصفية') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3">{{ __('الرقم') }}</th><th class="py-2 px-3">{{ __('التاريخ') }}</th>
                        <th class="py-2 px-3">{{ __('الخزينة') }}</th><th class="py-2 px-3">{{ __('الطرف') }}</th>
                        <th class="py-2 px-3">{{ __('المبلغ') }}</th><th class="py-2 px-3">{{ __('الحالة') }}</th><th class="py-2 px-3"></th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($vouchers as $v)
                            <tr>
                                <td class="py-2 px-3 font-mono text-xs">{{ $v->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $v->voucher_date->format('Y-m-d') }}</td>
                                <td class="py-2 px-3 text-gray-600">{{ $v->treasury?->name }}</td>
                                <td class="py-2 px-3 text-gray-600">{{ $v->party_name ?: ($v->counterAccount?->name) }}</td>
                                <td class="py-2 px-3 font-bold">{{ number_format($v->amount, 2) }}</td>
                                <td class="py-2 px-3"><x-accounting.status :status="$v->status" /></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.accounting.vouchers.show', [$kind, $v]) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('لا توجد سندات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $vouchers->links() }}</div>
        </div>
    </div>
</x-app-layout>
