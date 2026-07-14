<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزون والمشتريات') }} — {{ __('الموردون') }}</h2></x-slot>

    @php($currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <x-admin.flash />

        <div class="bg-white shadow-sm sm:rounded-lg">
            {{-- شريط علوي: عنوان + زر مورد جديد --}}
            <div class="flex items-center justify-between p-5 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">{{ __('الموردون') }}</h3>
                @can('create', \App\Modules\Purchasing\Models\Supplier::class)
                    <a href="{{ route('admin.purchasing.suppliers.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        {{ __('مورد جديد') }}
                    </a>
                @endcan
            </div>

            {{-- تبويبات الفلترة --}}
            @php($tabs = ['all' => __('الكل'), 'active' => __('نشط'), 'inactive' => __('غير نشط'), 'with_balance' => __('بأرصدة')])
            <div class="flex flex-wrap items-center gap-1 px-5 pt-4">
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('admin.purchasing.suppliers.index', array_filter(['filter' => $key, 'search' => $search])) }}"
                       class="px-3 py-1.5 text-sm rounded-lg border {{ $filter === $key ? 'bg-emerald-50 border-emerald-300 text-emerald-700 font-medium' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">
                        {{ $label }}
                        @if (isset($counts[$key]))
                            <span class="text-xs text-gray-400">({{ $counts[$key] }})</span>
                        @endif
                    </a>
                @endforeach
            </div>

            {{-- بحث --}}
            <form method="GET" class="flex items-center gap-2 px-5 py-4">
                <input type="hidden" name="filter" value="{{ $filter }}" />
                <div class="relative flex-1 max-w-sm">
                    <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('بحث بالاسم أو الرمز أو الهاتف أو البريد') }}"
                           class="w-full ps-9 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <svg class="w-4 h-4 text-gray-400 absolute top-2.5 start-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                </div>
                <button class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">{{ __('بحث') }}</button>
            </form>

            {{-- الجدول --}}
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-start">
                    <thead class="text-gray-500 bg-gray-50 border-y border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('اسم المورد') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الهاتف') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('البريد الإلكتروني') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الرصيد') }}</th>
                            <th class="py-3 px-4 font-medium text-center">{{ __('نشط') }}</th>
                            <th class="py-3 px-4 font-medium text-center">{{ __('إجراءات') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($suppliers as $s)
                            @php($balance = (float) $s->opening_balance + (float) ($s->invoices_due ?? 0))
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4">
                                    <a href="{{ route('admin.purchasing.suppliers.show', $s) }}" class="flex items-center gap-2 text-gray-900 font-medium hover:text-emerald-700">
                                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 text-xs font-bold shrink-0">{{ mb_substr($s->name, 0, 1) }}</span>
                                        <span>{{ $s->name }}<span class="block text-xs text-gray-400 font-normal">{{ $s->code }}</span></span>
                                    </a>
                                </td>
                                <td class="py-3 px-4 text-gray-500" dir="ltr">{{ $s->phone ?: '—' }}</td>
                                <td class="py-3 px-4 text-gray-500">{{ $s->email ?: '—' }}</td>
                                <td class="py-3 px-4">
                                    <span class="font-semibold tabular-nums {{ abs($balance) < 0.01 ? 'text-gray-400' : ($balance > 0 ? 'text-rose-600' : 'text-emerald-600') }}">
                                        {{ number_format($balance, 2) }} {{ $currency }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full {{ $s->is_active ? 'bg-emerald-500' : 'bg-gray-300' }}" title="{{ $s->is_active ? __('نشط') : __('غير نشط') }}"></span>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center justify-center gap-3">
                                        {{-- أيقونة فتح صفحة المورد --}}
                                        <a href="{{ route('admin.purchasing.suppliers.show', $s) }}" class="text-gray-400 hover:text-emerald-600" title="{{ __('عرض صفحة المورد') }}">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1 1 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178a1 1 0 010 .644C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        </a>
                                        @can('update', $s)
                                            <a href="{{ route('admin.purchasing.suppliers.edit', $s) }}" class="text-gray-400 hover:text-emerald-600" title="{{ __('تعديل') }}">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                            </a>
                                        @endcan
                                        @can('delete', $s)
                                            <form method="POST" action="{{ route('admin.purchasing.suppliers.destroy', $s) }}" onsubmit="return confirm('{{ __('حذف المورد؟') }}')">@csrf @method('DELETE')
                                                <button class="text-gray-400 hover:text-rose-600" title="{{ __('حذف') }}">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-gray-400">{{ __('لا يوجد موردون.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-gray-100">{{ $suppliers->links() }}</div>
        </div>
    </div>
</x-app-layout>
