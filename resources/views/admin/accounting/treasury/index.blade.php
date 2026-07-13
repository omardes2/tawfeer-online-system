<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $isBank ? __('الحسابات البنكية') : __('الخزائن النقدية') }}</h2></x-slot>
    <div class="py-8 max-w-6xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="$isBank ? __('البنوك') : __('الخزائن')">
                @can('accounting.'.($isBank ? 'banks' : 'cashboxes').'.manage')
                    <a href="{{ route('admin.accounting.'.$rp.'.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('إضافة') }}</a>
                @endcan
            </x-admin.header>

            <div class="mb-4 p-4 rounded-lg bg-emerald-50 border border-emerald-100 flex items-center justify-between">
                <span class="text-sm text-gray-600">{{ __('إجمالي الأرصدة') }}</span>
                <span class="text-2xl font-bold text-emerald-700">{{ number_format($total, 2) }}</span>
            </div>

            <form method="GET" class="mb-4"><input type="text" name="search" value="{{ $search }}" placeholder="{{ __('بحث بالاسم أو الرمز') }}" class="rounded-md border-gray-300 text-sm w-64" /><button class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('بحث') }}</button></form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3">{{ __('الرمز') }}</th><th class="py-2 px-3">{{ __('الاسم') }}</th>
                        @if ($isBank)<th class="py-2 px-3">{{ __('البنك') }}</th>@endif
                        <th class="py-2 px-3">{{ __('العملة') }}</th><th class="py-2 px-3">{{ __('الرصيد') }}</th>
                        <th class="py-2 px-3">{{ __('الحالة') }}</th><th class="py-2 px-3">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($treasuries as $t)
                            <tr>
                                <td class="py-2 px-3 font-mono text-xs">{{ $t->code }}</td>
                                <td class="py-2 px-3 text-gray-800">{{ $t->name }} @if($t->is_default)<span class="text-emerald-600 text-xs">★</span>@endif</td>
                                @if ($isBank)<td class="py-2 px-3 text-gray-500">{{ $t->bank_name ?: '—' }}</td>@endif
                                <td class="py-2 px-3 text-gray-500">{{ $t->currency }}</td>
                                <td class="py-2 px-3 font-bold {{ $t->current_balance < 0 ? 'text-rose-600' : 'text-gray-800' }}">{{ number_format($t->current_balance, 2) }}</td>
                                <td class="py-2 px-3"><span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $t->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $t->is_active ? __('نشط') : __('معطّل') }}</span></td>
                                <td class="py-2 px-3">
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('admin.accounting.'.$rp.'.show', $t) }}" class="text-sky-600 hover:underline">{{ __('كشف') }}</a>
                                        @can('accounting.'.($isBank ? 'banks' : 'cashboxes').'.manage')
                                            <a href="{{ route('admin.accounting.'.$rp.'.edit', $t) }}" class="text-emerald-600 hover:underline">{{ __('تعديل') }}</a>
                                            <form method="POST" action="{{ route('admin.accounting.'.$rp.'.destroy', $t) }}" onsubmit="return confirm('{{ __('حذف؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('لا توجد سجلات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
